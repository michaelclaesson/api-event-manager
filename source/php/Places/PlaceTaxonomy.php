<?php

namespace EventManager\Places;

use EventManager\Taxonomies\Taxonomy;

class PlaceTaxonomy extends Taxonomy
{
    public const NAME = 'event_place';

    public function getName(): string
    {
        return self::NAME;
    }
    public function getObjectType(): string
    {
        return 'event';
    }
    public function getLabelSingular(): string
    {
        return $this->wpService->__('Place', 'api-event-manager');
    }
    public function getLabelPlural(): string
    {
        return $this->wpService->__('Places', 'api-event-manager');
    }

    public function getArgs(): array
    {
        return [
            'public'       => true,
            'hierarchical' => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'rest_base'    => 'event-places',
            'meta_box_cb'  => false,
            'rewrite'      => false,
            'capabilities' => [
                'manage_terms' => 'manage_categories',
                'edit_terms'   => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'assign_event_terms',
            ],
        ];
    }
}
