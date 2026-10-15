<?php

namespace EventManager\Places;

use WpService\WpService;
use WP_Error;
use WP_Term;

class PlaceRepository
{
    public function __construct(private WpService $wpService)
    {
    }

    public function get(int $id): ?array
    {
        $term = $this->wpService->getTerm($id, PlaceTaxonomy::NAME);
        if (!$term instanceof WP_Term) {
            return null;
        }
        $address = PlaceAddress::from($this->wpService->getTermMeta($id, 'locationAddress', true));
        return $address ? ['id' => $id, 'slug' => $term->slug, 'name' => $term->name, 'address' => $address] : null;
    }

    public function matches(string $name): array
    {
        $normalized = PlaceName::normalize($name);
        if ($normalized === '') {
            return [];
        }
        // A SQL name comparison alone does not handle repeated whitespace or Unicode.
        $terms = $this->wpService->getTerms(['taxonomy' => PlaceTaxonomy::NAME, 'hide_empty' => false]);
        if ($this->wpService->isWpError($terms)) {
            return [];
        }
        $matches = [];
        foreach ($terms as $term) {
            if (PlaceName::normalize($term->name) === $normalized && ($place = $this->get($term->term_id))) {
                $matches[] = $place;
            }
        }
        return $matches;
    }

    public function create(string $name, array $address, int $eventId): int|WP_Error
    {
        // An explicit unique slug permits identical display names at different addresses.
        $slug   = $this->wpService->sanitizeTitle($name) . '-' . $eventId . '-' . substr(hash('sha256', serialize($address)), 0, 8);
        $result = $this->wpService->wpInsertTerm($name, PlaceTaxonomy::NAME, ['slug' => $slug]);
        if ($this->wpService->isWpError($result)) {
            return $result;
        }
        $id     = (int) $result['term_id'];
        $stored = $this->wpService->updateTermMeta($id, 'locationAddress', $address);
        if ($this->wpService->isWpError($stored) || $stored === false) {
            $this->wpService->wpDeleteTerm($id, PlaceTaxonomy::NAME);
            return $stored instanceof WP_Error ? $stored : new WP_Error('place_address_failed', $this->wpService->__('Could not save the place address.', 'api-event-manager'));
        }
        return $id;
    }
}
