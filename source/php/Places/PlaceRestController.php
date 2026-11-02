<?php

namespace EventManager\Places;

use EventManager\HooksRegistrar\Hookable;
use WpService\WpService;

class PlaceRestController implements Hookable
{
    public function __construct(private WpService $wpService, private PlaceRepository $places)
    {
    }

    public function addHooks(): void
    {
        $this->wpService->addAction('rest_api_init', [$this, 'register']);
    }

    public function register(): void
    {
        $this->wpService->registerRestField(PlaceTaxonomy::NAME, 'place', [
            'get_callback' => fn(array $term) => $this->format($this->places->get((int) $term['id']))['place'] ?? null,
            'schema'       => ['type' => ['object', 'null'], 'context' => ['view', 'edit']],
        ]);
        $this->wpService->registerRestRoute('event-manager/v1', '/places/matches', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => ['name' => ['type' => 'string', 'required' => true, 'maxLength' => 200]],
            'callback'            => fn(\WP_REST_Request $request) => array_map(
                [$this, 'format'],
                $this->places->matches($this->wpService->sanitizeTextField($request->get_param('name')))
            ),
        ]);
    }

    public function format(?array $place): ?array
    {
        if (!$place) {
            return null;
        }
        return [
            'id'    => $place['id'],
            'slug'  => $place['slug'],
            'name'  => $place['name'],
            'place' => PlaceAddress::schema($place['address'], $place['name']) + ['shortAddress' => PlaceAddress::short($place['address'])],
        ];
    }
}
