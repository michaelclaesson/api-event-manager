<?php
/** Run with: wp --url=https://event.eslov.local --user=<editor> eval-file tests/integration/places.php */

use EventManager\Places\{PlaceFields, PlaceRepository, PlaceTaxonomy};
use WpService\Implementations\NativeWpService;

if (!defined('WP_CLI') || !str_ends_with((string) parse_url(home_url(), PHP_URL_HOST), '.local')) {
    throw new RuntimeException('Run this integration check only on a .local development site.');
}
if (!current_user_can('manage_categories')) {
    throw new RuntimeException('An editor with permission to manage places is required.');
}

// Integration fixtures must never send notifications or invoke remote indexing.
add_filter('pre_wp_mail', '__return_true');
add_filter('pre_http_request', fn() => new WP_Error('integration_test', 'External requests disabled during test.'));

$marker = 'Testplats ' . uniqid('places-');
$posts = [];
$terms = [];
$checks = 0;
$check = function (bool $valid, string $message) use (&$checks): void {
    if (!$valid) {
        throw new RuntimeException($message);
    }
    $checks++;
    WP_CLI::log('PASS ' . $message);
};
$address = ['address' => 'Testgatan 1, Eslöv', 'lat' => 55.8346, 'lng' => 13.3028, 'address_locality' => 'Eslöv'];
$repo = new PlaceRepository(new NativeWpService());

try {
    $id = wp_insert_post(['post_type' => 'event', 'post_status' => 'pending', 'post_title' => $marker,
        'meta_input' => ['mod_frontend_form_module_id' => 12]], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $posts[] = $id;
    update_field(PlaceFields::SOURCE, 'new', $id);
    update_field(PlaceFields::NAME, $marker, $id);
    update_field(PlaceFields::ADDRESS, $address, $id);
    update_field(PlaceFields::DETAILS, 'Konferensrum 1', $id);
    update_field(PlaceFields::ATTENDANCE, 'offline', $id);
    do_action('ModularityFrontendForm/afterInsertPost', $id);
    $check($repo->matches($marker) === [], 'Pending proposals are absent from the shared register');

    $result = \EslovEventReview\decide($id, 'approve');
    $termId = (int) get_post_meta($id, 'eventPlace', true);
    if ($termId) $terms[] = $termId;
    $check(!is_wp_error($result) && get_post_status($id) === 'publish' && $termId > 0, 'Quick approval creates and assigns a place');
    $check(get_field('locationSource', $id) === 'existing', 'Approved proposal switches to an existing place');
    wp_update_post(['ID' => $id]);
    $check(count($repo->matches($marker)) === 1, 'Repeated publishing does not create duplicate places');

    $request = new WP_REST_Request('GET', '/event-manager/v1/places/matches');
    $request->set_param('name', '  ' . mb_strtolower($marker) . '  ');
    $response = rest_do_request($request);
    $check($response->get_status() === 200 && count($response->get_data()) === 1, 'REST matching ignores case and surrounding whitespace');
    $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/event-places/' . $termId));
    $check(($response->get_data()['place']['address'] ?? null) === $address['address'], 'Term REST response includes the saved address');

    $second = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => $marker . ' second']);
    $posts[] = $second;
    update_field(PlaceFields::SOURCE, 'existing', $second);
    update_field(PlaceFields::PLACE, $termId, $second);
    update_field(PlaceFields::DETAILS, 'Kafeterian', $second);
    do_action('ModularityFrontendForm/afterInsertPost', $second);
    $check(get_field('locationName', $second) === $marker, 'Another event reuses the canonical name');

    $slug = get_term($termId)->slug;
    wp_update_term($termId, PlaceTaxonomy::NAME, ['name' => $marker . ' ändrad']);
    $newAddress = [...$address, 'address' => 'Testgatan 2, Eslöv'];
    update_field('field_event_place_address', $newAddress, 'term_' . $termId);
    do_action('acf/save_post', 'term_' . $termId);
    $check(get_term($termId)->slug === $slug, 'Renaming preserves the term slug');
    foreach ([$id, $second] as $eventId) {
        $check(get_field('locationName', $eventId) === $marker . ' ändrad'
            && get_field('locationAddress', $eventId)['address'] === $newAddress['address'], 'Register edits propagate to event ' . $eventId);
    }
    $check(get_field('locationDetails', $id) === 'Konferensrum 1' && get_field('locationDetails', $second) === 'Kafeterian', 'Per-event room details remain independent');

    $other = $repo->create($marker . ' ändrad', $address, $second);
    if (!is_wp_error($other)) $terms[] = $other;
    $check(!is_wp_error($other) && $other !== $termId, 'Identical names are permitted at different addresses');

    $legacy = wp_insert_post(['post_type' => 'event', 'post_status' => 'draft', 'post_title' => $marker . ' legacy',
        'meta_input' => ['locationName' => 'Tidigare platsnamn', 'locationAddress' => $address]]);
    $posts[] = $legacy;
    update_field(PlaceFields::NAME, 'Tidigare platsnamn', $legacy);
    update_field(PlaceFields::ADDRESS, $address, $legacy);
    wp_update_post(['ID' => $legacy]);
    $check(get_post_meta($legacy, 'locationName', true) === 'Tidigare platsnamn' && !get_post_meta($legacy, 'eventPlace', true), 'Legacy events are preserved without automatic linking');
    $token = wp_generate_password(32, false, false);
    wp_update_post(['ID' => $legacy, 'post_password' => $token]);
    $request = new WP_REST_Request('GET', '/modularity-frontend-form/v1/read/get');
    foreach (['module-id' => 12, 'post-id' => $legacy, 'token' => $token, 'nonce' => wp_create_nonce(md5(serialize(get_post(12))))] as $key => $value) $request->set_param($key, $value);
    $loaded = rest_do_request($request);
    $check($loaded->get_status() === 200 && ($loaded->get_data()['data'][PlaceFields::SOURCE] ?? null) === 'legacy', 'Editing API loads an explicit legacy mode for old events');

    update_field(PlaceFields::ATTENDANCE, 'online', $second);
    do_action('ModularityFrontendForm/afterUpdatePost', $second);
    $check(!get_post_meta($second, 'eventPlace', true) && !wp_get_object_terms($second, PlaceTaxonomy::NAME), 'Online events have no physical place association');

    // Exercise the actual public submission endpoint and its cross-field validation.
    $submit = function (array $fields) use ($marker): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/modularity-frontend-form/v1/submit/post');
        $request->set_param('module-id', 12);
        $request->set_param('holding-post-id', (int) get_option('page_on_front'));
        $request->set_param('nonce', wp_create_nonce(md5(serialize(get_post(12)))));
        $request->set_param('mod-frontend-form', ['post_title' => $marker . ' API', 'post_content' => '<p>Integrationstest</p>', ...$fields]);
        return rest_do_request($request);
    };
    $bad = $submit([PlaceFields::SOURCE => 'existing', PlaceFields::PLACE => 9999999]);
    $check($bad->get_status() === 400 && ($bad->get_data()['data']['reason'] ?? '') === 'invalid_event_place', 'Server rejects an unknown place before inserting an event');
    $good = $submit([
        PlaceFields::SOURCE => 'existing', PlaceFields::PLACE => $termId,
        PlaceFields::ADDRESS => json_encode(['@type' => 'Place', 'name' => 'Forged name', 'address' => ['name' => 'Forged address'], 'latitude' => 0, 'longitude' => 0]),
        PlaceFields::DETAILS => 'Rum API', PlaceFields::ATTENDANCE => 'offline',
    ]);
    $check($good->get_status() === 200, 'Actual frontend submission endpoint accepts a registered place: ' . wp_json_encode($good->get_data()));
    $apiPosts = get_posts(['post_type' => 'event', 'post_status' => 'any', 's' => $marker . ' API', 'numberposts' => -1]);
    foreach ($apiPosts as $post) $posts[] = $post->ID;
    $apiId = $apiPosts[0]->ID ?? 0;
    $check($apiId && get_post_status($apiId) === 'pending' && get_field('locationName', $apiId) === $marker . ' ändrad'
        && get_field('locationAddress', $apiId)['address'] === $newAddress['address'], 'Submission uses canonical data and awaits review');

    $apiToken = wp_generate_password(32, false, false);
    wp_update_post(['ID' => $apiId, 'post_password' => $apiToken]);
    $update = function (string $token) use ($apiId, $termId): WP_REST_Response {
        $request = new WP_REST_Request('POST', '/modularity-frontend-form/v1/submit/update');
        foreach (['module-id' => 12, 'post-id' => $apiId, 'token' => $token,
            'holding-post-id' => (int) get_option('page_on_front'),
            'nonce' => wp_create_nonce(md5(serialize(get_post(12))))] as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_param('mod-frontend-form', [
            'post_title' => get_the_title($apiId), 'post_content' => '<p>Uppdaterat integrationstest</p>',
            PlaceFields::SOURCE => 'existing', PlaceFields::PLACE => $termId,
            PlaceFields::DETAILS => 'Uppdaterat rum', PlaceFields::ATTENDANCE => 'offline',
        ]);
        return rest_do_request($request);
    };
    $badUpdate = $update('invalid-token');
    $check($badUpdate->get_status() === 400 && get_field('locationDetails', $apiId) === 'Rum API', 'Invalid edit tokens cannot modify an event');
    $goodUpdate = $update($apiToken);
    $check($goodUpdate->get_status() === 200 && get_field('locationDetails', $apiId) === 'Uppdaterat rum'
        && get_field('locationName', $apiId) === $marker . ' ändrad', 'Authenticated editing updates the original event and keeps its canonical place');
    WP_CLI::success($checks . ' place integration checks passed.');
} finally {
    // Delete only records created by this run.
    foreach (get_posts(['post_type' => 'event', 'post_status' => 'any', 's' => $marker, 'numberposts' => -1]) as $post) $posts[] = $post->ID;
    foreach (array_unique($posts) as $id) wp_delete_post($id, true);
    foreach (array_unique($terms) as $id) wp_delete_term($id, PlaceTaxonomy::NAME);
}
