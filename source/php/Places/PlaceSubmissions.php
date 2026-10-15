<?php

namespace EventManager\Places;

use AcfService\AcfService;
use EventManager\HooksRegistrar\Hookable;
use WpService\WpService;
use WP_Error;

class PlaceSubmissions implements Hookable
{
    private bool $saving = false;

    public function __construct(private WpService $wpService, private AcfService $acfService, private PlaceRepository $places)
    {
    }

    public function addHooks(): void
    {
        $this->wpService->addAction('acf/save_post', [$this, 'save'], 30);
        $this->wpService->addAction('wp_after_insert_post', [$this, 'save'], 30);
        $this->wpService->addAction('ModularityFrontendForm/afterInsertPost', [$this, 'save']);
        $this->wpService->addAction('ModularityFrontendForm/afterUpdatePost', [$this, 'save']);
        $this->wpService->addAction('edited_' . PlaceTaxonomy::NAME, [$this, 'updateEvents']);
        $this->wpService->addAction('acf/save_post', [$this, 'termAddressSaved'], 40);
        $this->wpService->addAction('pre_delete_term', [$this, 'preventDeletingUsedPlace'], 10, 2);
        $this->wpService->addFilter('ModularityFrontendForm/validateSubmission', [$this, 'validateFrontend'], 10, 5);
        $this->wpService->addAction('acf/validate_save_post', [$this, 'validateAdmin']);
        $this->wpService->addFilter('Municipio/PostObject/getSchema', [$this, 'applySchema'], 20, 2);
        $this->wpService->addFilter('ModularityFrontendForm/readFormData', [$this, 'loadMissingFields'], 10, 3);
    }

    /** Old events have no stored ACF references for the newly added fields. */
    public function loadMissingFields(array $data, int $postId, array $allowedFields): array
    {
        if ($this->wpService->getPostType($postId) !== 'event') {
            return $data;
        }
        foreach ([PlaceFields::SOURCE, PlaceFields::PLACE, PlaceFields::DETAILS] as $key) {
            if (!array_key_exists($key, $data) && in_array($key, $allowedFields, true)) {
                $value      = $this->acfService->getField($key, $postId);
                $data[$key] = $key === PlaceFields::PLACE ? (int) $value : ($value ?: '');
            }
        }
        return $data;
    }

    /** Run after ACF values exist, including quick approval through wp_update_post(). */
    public function save(mixed $postId): void
    {
        if (
            !is_numeric($postId) || $this->saving || $this->wpService->getPostType((int) $postId) !== 'event'
            || $this->wpService->wpIsPostRevision((int) $postId)
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        ) {
            return;
        }
        $postId = (int) $postId;
        $mode   = $this->wpService->getPostMeta($postId, 'locationSource', true);
        if (!$mode) {
            return; // An untouched legacy event, or an insert before form fields were stored.
        }
        $this->saving = true;
        try {
            if ($this->wpService->getPostMeta($postId, 'attendancemode', true) === 'online') {
                $schema = $this->wpService->getPostMeta($postId, 'schemaData', true);
                if (is_array($schema) && ($schema['@type'] ?? null) === 'Event') {
                    $schema['location'] = array_values(array_filter($schema['location'] ?? [], fn($place) => ($place['@type'] ?? null) !== 'Place'));
                    $this->wpService->updatePostMeta($postId, 'schemaData', $schema);
                }
                $this->wpService->wpSetObjectTerms($postId, [], PlaceTaxonomy::NAME);
                foreach (['eventPlace' => 0, 'locationName' => '', 'locationAddress' => '', 'locationDetails' => ''] as $key => $value) {
                    $this->acfService->updateField($key, $value, $postId);
                }
                return;
            }
            if ($mode === 'new' && $this->wpService->getPostStatus($postId) === 'publish') {
                if (!$this->wpService->currentUserCan('manage_categories')) {
                    $this->failPublication($postId);
                    return;
                }
                $name    = $this->wpService->sanitizeTextField($this->wpService->getPostMeta($postId, 'locationName', true));
                $address = PlaceAddress::from($this->wpService->getPostMeta($postId, 'locationAddress', true));
                if ($name === '' || !$address) {
                    $this->failPublication($postId);
                    return;
                }
                // Keep the created ID before any further save can run.
                $fingerprint = hash('sha256', $name . serialize($address));
                $created     = $this->wpService->getPostMeta($postId, '_event_place_created_for_submission', true);
                $placeId     = is_array($created) && ($created['fingerprint'] ?? null) === $fingerprint && $this->places->get((int) $created['id'])
                    ? (int) $created['id'] : $this->places->create($name, $address, $postId);
                if ($this->wpService->isWpError($placeId)) {
                    $this->failPublication($postId);
                    return;
                }
                $this->wpService->updatePostMeta($postId, '_event_place_created_for_submission', ['id' => $placeId, 'fingerprint' => $fingerprint]);
                $this->acfService->updateField(PlaceFields::PLACE, $placeId, $postId);
                $this->acfService->updateField(PlaceFields::SOURCE, 'existing', $postId);
                $mode = 'existing';
            }
            if ($mode === 'existing') {
                $place = $this->places->get((int) $this->wpService->getPostMeta($postId, 'eventPlace', true));
                if (!$place) {
                    if ($this->wpService->getPostStatus($postId) === 'publish') {
                        $this->failPublication($postId);
                    }
                    return;
                }
                $this->wpService->wpSetObjectTerms($postId, [$place['id']], PlaceTaxonomy::NAME);
                $this->acfService->updateField(PlaceFields::NAME, $place['name'], $postId);
                $this->acfService->updateField(PlaceFields::ADDRESS, $place['address'], $postId);
            } else {
                // A proposal must not retain a previous place's taxonomy relationship.
                $this->wpService->wpSetObjectTerms($postId, [], PlaceTaxonomy::NAME);
                $this->acfService->updateField(PlaceFields::PLACE, 0, $postId);
            }
            $this->refreshStoredSchema($postId);
        } finally {
            $this->saving = false;
        }
    }

    private function failPublication(int $postId): void
    {
        $this->wpService->wpUpdatePost(['ID' => $postId, 'post_status' => 'pending']);
        $this->wpService->doAction('EventManager/PlacePublicationFailed', $postId);
    }

    public function validateFrontend(mixed $valid, array $data, \WP_REST_Request $request, mixed $moduleConfig, mixed $config): mixed
    {
        if ($valid instanceof WP_Error || ($moduleConfig->getWpDbHandlerConfig()->saveToPostType ?? null) !== 'event') {
            return $valid;
        }
        $fields = $data[$config->getFieldNamespace()] ?? [];
        if (
            !array_key_exists(PlaceFields::SOURCE, $fields)
            && !in_array(PlaceFields::SOURCE, $moduleConfig->getFieldKeysRegisteredAsFormFields() ?? [], true)
        ) {
            return $valid;
        }
        $postId = (int) ($request->get_param('post-id') ?? 0);
        return $this->validate($fields, $postId) ?? $valid;
    }

    public function validateAdmin(): void
    {
        $fields = $_POST['acf'] ?? [];
        if (!is_array($fields) || !array_key_exists(PlaceFields::SOURCE, $fields)) {
            return;
        }
        $error = $this->validate($fields, (int) ($_POST['post_ID'] ?? 0));
        if ($error) {
            acf_add_validation_error('acf[' . PlaceFields::SOURCE . ']', $error->get_error_message());
        }
    }

    public function validate(array $fields, int $postId = 0): ?WP_Error
    {
        if (($fields[PlaceFields::ATTENDANCE] ?? 'offline') === 'online') {
            return null;
        }
        $source = $fields[PlaceFields::SOURCE] ?? '';
        $valid  = match ($source) {
            'existing' => is_scalar($fields[PlaceFields::PLACE] ?? null)
                && $this->places->get((int) $fields[PlaceFields::PLACE]) !== null,
            'new' => is_string($fields[PlaceFields::NAME] ?? null)
                && PlaceName::normalize(strip_tags($fields[PlaceFields::NAME])) !== '' && mb_strlen($fields[PlaceFields::NAME]) <= 200
                && PlaceAddress::from($fields[PlaceFields::ADDRESS] ?? null) !== null,
            'legacy' => $postId > 0 && $this->wpService->getPostType($postId) === 'event'
                && !$this->wpService->getPostMeta($postId, 'eventPlace', true)
                && in_array($this->wpService->getPostMeta($postId, 'locationSource', true), ['', 'legacy'], true)
                && PlaceAddress::from($fields[PlaceFields::ADDRESS] ?? null) !== null,
            default => false,
        };
        $valid = $valid && (!isset($fields[PlaceFields::DETAILS]) || is_string($fields[PlaceFields::DETAILS]));
        return $valid ? null : new WP_Error('invalid_event_place', $this->wpService->__(
            'Choose an existing place or enter a name and a valid address for a new place.',
            'api-event-manager'
        ), ['status' => 400]);
    }

    public function termAddressSaved(mixed $postId): void
    {
        if (is_string($postId) && preg_match('/^term_(\d+)$/', $postId, $match)) {
            $this->updateEvents((int) $match[1]);
        }
    }

    public function updateEvents(int $termId): void
    {
        if (!$this->places->get($termId)) {
            return;
        }
        $ids = $this->wpService->getObjectsInTerm($termId, PlaceTaxonomy::NAME);
        if ($this->wpService->isWpError($ids)) {
            return;
        }
        foreach ($ids as $id) {
            $this->save((int) $id);
            $this->wpService->cleanPostCache((int) $id);
            $this->wpService->doAction('EventManager/PlaceUpdatedForEvent', (int) $id, $termId);
        }
    }

    public function preventDeletingUsedPlace(int $termId, string $taxonomy): void
    {
        if ($taxonomy !== PlaceTaxonomy::NAME) {
            return;
        }
        $ids = $this->wpService->getObjectsInTerm($termId, $taxonomy);
        if (!$this->wpService->isWpError($ids) && $ids) {
            $this->wpService->wpDie($this->wpService->__('This place is used by events and cannot be deleted.', 'api-event-manager'), '', ['response' => 403]);
        }
    }

    /** Decorate only linked offline events; preserve all unrelated schema properties. */
    public function applySchema(mixed $schema, mixed $post): mixed
    {
        if (!is_object($schema) || !method_exists($schema, 'location')) {
            return $schema;
        }
        $id = $post->getId();
        if ($post->getPostType() !== 'event' || $this->wpService->getPostMeta($id, 'attendancemode', true) === 'online') {
            return $schema;
        }
        $place = $this->places->get((int) $this->wpService->getPostMeta($id, 'eventPlace', true));
        if ($place && class_exists(\Municipio\Schema\Schema::class)) {
            $details  = (string) $this->wpService->getPostMeta($id, 'locationDetails', true);
            $location = \Municipio\Schema\Schema::place();
            foreach (PlaceAddress::schema($place['address'], $place['name'], $details) as $property => $value) {
                $location->setProperty($property, $value);
            }
            $schema->location([$location]);
        }
        return $schema;
    }

    private function refreshStoredSchema(int $postId): void
    {
        $schema = $this->wpService->getPostMeta($postId, 'schemaData', true);
        if (!is_array($schema) || ($schema['@type'] ?? null) !== 'Event') {
            return;
        }
        $address = PlaceAddress::from($this->wpService->getPostMeta($postId, 'locationAddress', true));
        if ($address) {
            $schema['location'] = [PlaceAddress::schema(
                $address,
                (string) $this->wpService->getPostMeta($postId, 'locationName', true),
                (string) $this->wpService->getPostMeta($postId, 'locationDetails', true)
            )];
            $this->wpService->updatePostMeta($postId, 'schemaData', $schema);
        }
    }
}
