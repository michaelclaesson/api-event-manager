<?php

namespace EventManager\Places;

use AcfService\Implementations\FakeAcfService;
use PHPUnit\Framework\TestCase;
use WpService\Implementations\FakeWpService;

class PlacesTest extends TestCase
{
    public function testNameNormalizationKeepsSwedishLettersAndIgnoresCaseAndWhitespace(): void
    {
        static::assertSame('gula rummet åäö', PlaceName::normalize("  GULA\u{00a0} RUMMET  ÅÄÖ \n"));
        static::assertNotSame(PlaceName::normalize('Åsen'), PlaceName::normalize('Asen'));
    }

    public function testAddressAcceptsSchemaPostalAddressAndZeroCoordinates(): void
    {
        $address = PlaceAddress::from(json_encode([
            'address' => ['@type' => 'PostalAddress', 'name' => 'Gatan 1', 'addressLocality' => 'Eslöv'],
            'latitude' => 0, 'longitude' => 0,
        ]));
        static::assertSame('Gatan 1', $address['address']);
        static::assertSame('Eslöv', $address['address_locality']);
        static::assertSame(0.0, $address['lat']);
        static::assertNull(PlaceAddress::from(['address' => 'Gatan', 'lat' => 91, 'lng' => 13]));
        static::assertNull(PlaceAddress::from(['address' => '', 'lat' => 55, 'lng' => 13]));
        static::assertNull(PlaceAddress::from(['address' => '<b></b>', 'lat' => 55, 'lng' => 13]));
    }

    public function testDetailsAreSeparateFromCanonicalPlaceName(): void
    {
        $schema = PlaceAddress::schema(['address' => 'Gatan 1', 'lat' => 55, 'lng' => 13], 'Hallen', 'Rum 1');
        static::assertSame('Hallen', $schema['name']);
        static::assertSame('Rum 1', $schema['description']);
        static::assertArrayNotHasKey('description', PlaceAddress::schema(['address' => 'Gatan 1', 'lat' => 55, 'lng' => 13], 'Hallen'));
    }

    public function testPendingProposalDoesNotCreateAPlace(): void
    {
        $places = $this->createMock(PlaceRepository::class);
        $places->expects(static::never())->method('create');
        $wp = $this->wp(['locationSource' => 'new', 'attendancemode' => 'offline'], 'pending');
        (new PlaceSubmissions($wp, new FakeAcfService(['updateField' => true]), $places))->save(123);
    }

    public function testNonEditorsCannotPublishAProposalIntoTheSharedRegister(): void
    {
        $places = $this->createMock(PlaceRepository::class);
        $places->expects(static::never())->method('create');
        $wp = $this->wp(['locationSource' => 'new', 'attendancemode' => 'offline']);
        (new PlaceSubmissions($wp, new FakeAcfService(['updateField' => true]), $places))->save(123);
        static::assertSame(['ID' => 123, 'post_status' => 'pending'], $wp->methodCalls['wpUpdatePost'][0][0]);
    }

    public function testUntouchedLegacyEventIsNotChanged(): void
    {
        $wp = $this->wp(['locationName' => 'Gamla namnet']);
        $acf = new FakeAcfService(['updateField' => true]);
        (new PlaceSubmissions($wp, $acf, $this->createMock(PlaceRepository::class)))->save(123);
        static::assertArrayNotHasKey('updateField', $acf->methodCalls);
        static::assertArrayNotHasKey('wpSetObjectTerms', $wp->methodCalls);
    }

    public function testAcfDefaultDoesNotHideALegacyLocation(): void
    {
        $fields = new PlaceFields($this->wp(['locationAddress' => ['address' => 'Gamla gatan', 'lat' => 55, 'lng' => 13]]));
        static::assertSame('legacy', $fields->loadSource('existing', 123, []));
        $fields = new PlaceFields($this->wp(['locationSource' => 'new', 'locationAddress' => ['address' => 'Gatan']]));
        static::assertSame('new', $fields->loadSource('existing', 123, []));
    }

    public function testExistingPlaceUsesCanonicalAddressAndPreservesDetails(): void
    {
        $places = $this->createMock(PlaceRepository::class);
        $places->method('get')->willReturn(['id' => 7, 'name' => 'Hallen', 'address' => ['address' => 'Gatan 1', 'lat' => 55, 'lng' => 13]]);
        $wp = $this->wp(['locationSource' => 'existing', 'eventPlace' => 7, 'locationDetails' => 'Rum 2']);
        $acf = new FakeAcfService(['updateField' => true]);
        (new PlaceSubmissions($wp, $acf, $places))->save(123);
        static::assertSame([123, [7], 'event_place'], $wp->methodCalls['wpSetObjectTerms'][0]);
        static::assertSame([PlaceFields::NAME, 'Hallen', 123], $acf->methodCalls['updateField'][0]);
        static::assertNotContains('locationDetails', array_column($acf->methodCalls['updateField'], 0));
    }

    public function testNewPlacesRequireValidNameAndAddressAndOnlineDoesNot(): void
    {
        $places = $this->createMock(PlaceRepository::class);
        $service = new PlaceSubmissions($this->wp([]), new FakeAcfService(['updateField' => true]), $places);
        static::assertNotNull($service->validate([PlaceFields::SOURCE => 'new']));
        static::assertNotNull($service->validate([PlaceFields::SOURCE => 'existing', PlaceFields::PLACE => 999]));
        static::assertNull($service->validate([PlaceFields::ATTENDANCE => 'online']));
        static::assertNull($service->validate([
            PlaceFields::SOURCE => 'new', PlaceFields::NAME => 'Hallen',
            PlaceFields::ADDRESS => ['address' => 'Gatan 1', 'lat' => 55, 'lng' => 13],
        ]));
    }

    private function wp(array $meta, string $status = 'publish'): FakeWpService
    {
        return new FakeWpService([
            '__' => fn($text) => $text, 'getPostType' => 'event', 'wpIsPostRevision' => false, 'getPostStatus' => $status,
            'getPostMeta' => fn($id, $key) => $meta[$key] ?? '',
            'wpSetObjectTerms' => [], 'isWpError' => false, 'wpUpdatePost' => 123, 'currentUserCan' => false,
        ]);
    }
}
