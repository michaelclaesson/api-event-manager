<?php

namespace EventManager\Places;

use EventManager\HooksRegistrar\Hookable;
use WpService\WpService;

class PlaceFields implements Hookable
{
    public const SOURCE     = 'field_event_location_source';
    public const PLACE      = 'field_event_place';
    public const DETAILS    = 'field_event_location_details';
    public const NAME       = 'field_696e24e566ca3';
    public const ADDRESS    = 'field_696e24e567083';
    public const ATTENDANCE = 'field_696e24e5660ed';

    public function __construct(private WpService $wpService)
    {
    }

    public function addHooks(): void
    {
        $this->wpService->addFilter('acf/load_field/key=' . self::PLACE, [$this, 'configureSelector']);
        $this->wpService->addFilter('acf/load_value/key=' . self::SOURCE, [$this, 'loadSource'], 10, 3);
        $this->wpService->addAction('acf/init', [$this, 'registerAddressFields']);
        $this->wpService->addFilter('acf/update_value/key=' . self::DETAILS, function (mixed $value): string {
            return $this->wpService->sanitizeTextField(is_scalar($value) ? (string) $value : '');
        });
        $this->wpService->addFilter('acf/prepare_field/key=' . self::SOURCE, function (array $field): array {
            if ($this->wpService->isAdmin() && ($field['value'] ?? null) !== 'legacy') {
                unset($field['choices']['legacy']);
            }
            return $field;
        });
        $this->wpService->addFilter('acf/fields/taxonomy/result/key=' . self::PLACE, function (string $label, \WP_Term $term): string {
            $address = PlaceAddress::from($this->wpService->getTermMeta($term->term_id, 'locationAddress', true));
            return $address ? $label . ' (' . PlaceAddress::short($address) . ')' : $label;
        }, 10, 2);
    }

    public function loadSource(mixed $value, mixed $postId, array $field): mixed
    {
        if (is_numeric($postId) && $this->wpService->getPostType((int) $postId) === 'event') {
            $stored = $this->wpService->getPostMeta((int) $postId, 'locationSource', true);
            if (is_string($stored) && $stored !== '') {
                return $stored;
            }
            // ACF can already have substituted default_value before this filter runs.
            if ($this->wpService->getPostMeta((int) $postId, 'locationAddress', true)) {
                return $this->wpService->getPostMeta((int) $postId, 'eventPlace', true) ? 'existing' : 'legacy';
            }
        }
        return 'existing';
    }

    public function configureSelector(array $field): array
    {
        $field['placeholder']                     = $this->wpService->__('Choose a place', 'api-event-manager');
        $field['frontend_form_option_label_meta'] = 'locationAddress';
        $field['frontend_form_linked_location']   = [
            'sourceField'     => self::SOURCE,
            'nameField'       => self::NAME,
            'addressField'    => self::ADDRESS,
            'attendanceField' => self::ATTENDANCE,
            'termsUrl'        => $this->wpService->restUrl('wp/v2/event-places'),
            'matchesUrl'      => $this->wpService->restUrl('event-manager/v1/places/matches'),
            'strings'         => [
                'search'     => $this->wpService->__('Search places', 'api-event-manager'),
                'duplicate'  => $this->wpService->__('A place with this name already exists. Would you like to use it?', 'api-event-manager'),
                'duplicatePlural' => $this->wpService->__('Places with this name already exist. Would you like to use one of them?', 'api-event-manager'),
                'use'        => $this->wpService->__('Use this place', 'api-event-manager'),
                'continue'   => $this->wpService->__('Continue with new place', 'api-event-manager'),
                'checking'   => $this->wpService->__('Checking existing places…', 'api-event-manager'),
                'failed'     => $this->wpService->__('Could not check existing places. Try again or continue without checking.', 'api-event-manager'),
                'retry'      => $this->wpService->__('Try again', 'api-event-manager'),
                'skip'       => $this->wpService->__('Continue without checking', 'api-event-manager'),
                'loadFailed' => $this->wpService->__('Could not load the selected place. Select it again or submit a new place.', 'api-event-manager'),
            ],
        ];
        return $field;
    }

    public function registerAddressFields(): void
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
        $center = array_map('trim', explode(',', (string) $this->wpService->getThemeMod('map_start_lat_lng', '56.0467,12.6944')));
        if (count($center) !== 2 || !is_numeric($center[0]) || !is_numeric($center[1]) || abs((float) $center[0]) > 90 || abs((float) $center[1]) > 180) {
            $center = [56.0467, 12.6944];
        }
        acf_add_local_field_group([
            'key'          => 'group_event_place_address',
            'title'        => $this->wpService->__('Place address', 'api-event-manager'),
            'fields'       => [[
                'key'        => 'field_event_place_address',
                'name'       => 'locationAddress',
                'label'      => $this->wpService->__('Address and map position', 'api-event-manager'),
                'type'       => 'google_map',
                'required'   => 1,
                'center_lat' => $center[0],
                'center_lng' => $center[1],
            ]],
            'location'     => [[['param' => 'taxonomy', 'operator' => '==', 'value' => PlaceTaxonomy::NAME]]],
            'show_in_rest' => 1,
        ]);
    }
}
