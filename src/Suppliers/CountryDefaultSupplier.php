<?php

namespace Nevadskiy\Geonames\Suppliers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nevadskiy\Geonames\Geonames;
use Nevadskiy\Geonames\Models\Continent;
use Nevadskiy\Geonames\Models\Country;

class CountryDefaultSupplier extends DefaultSupplier implements CountrySupplier
{
    /**
     * The geonames instance.
     *
     * @var Geonames
     */
    protected $geonames;

    /**
     * The country information list.
     *
     * @var array
     */
    protected $countryInfos;

    /**
     * The continents collection.
     *
     * @var Collection
     */
    protected $continents;

    /**
     * Make a new supplier instance.
     */
    public function __construct(Geonames $geonames)
    {
        $this->geonames = $geonames;
    }

    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        parent::init();

        if ($this->geonames->shouldSupplyContinents()) {
            $this->continents = $this->getContinents();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setCountryInfos(array $countryInfo): void
    {
        $this->countryInfos = collect($countryInfo)
            ->keyBy('geonameid')
            ->toArray();
    }

    /**
     * {@inheritdoc}
     */
    protected function getModel(): Model
    {
        return $this->geonames->model('country');
    }

    /**
     * {@inheritdoc}
     */
    protected function shouldSupply(array $data, int $id): bool
    {
        if (! isset($this->countryInfos[$id])) {
            return false;
        }

        return $this->geonames->isCountryAllowed($this->countryInfos[$id]['ISO']);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapInsertFields(array $data, int $id): array
    {
        return array_merge($this->mapUpdateFields($data, $id), [
            //'id' => Country::generateId(),
            'geoname_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUpdateFields(array $data, int $id): array
    {
        return array_merge(
            $this->mapCountryInfoFields($this->countryInfos[$id]),
            $this->mapCountryFields($data, $id)
        );
    }

    /**
     * Map country table fields.
     */
    protected function mapCountryFields(array $data, int $id): array
    {
        return [
            'name_official' => $data['asciiname'] ?: $data['name'],
            'timezone_id' => $data['timezone'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'population' => $data['population'],
            'dem' => $data['dem'],
            'feature_code' => $data['feature code'],
            'modified_at' => $data['modification date'],
        ];
    }

    /**
     * Map country info table fields.
     */
    protected function mapCountryInfoFields(array $data): array
    {
        return [
            'code' => $data['ISO'],
            'iso' => $data['ISO3'],
            'iso_numeric' => $data['ISO-Numeric'],
            'name' => $data['Country'],
            'continent_id' => function () use ($data) {
                return $this->continents[$data['Continent']]->id;
            },
            'capital' => $data['Capital'],
            'currency_code' => $data['CurrencyCode'],
            'currency_name' => $data['CurrencyName'],
            'tld' => $data['tld'],
            'phone_code' => $data['Phone'],
            'postal_code_format' => $data['Postal Code Format'],
            'postal_code_regex' => $data['Postal Code Regex'],
            'languages' => $data['Languages'],
            'neighbours' => $data['neighbours'],
            'area' => $data['Area(in sq km)'],
            'fips' => $data['fips'],
        ];
    }

    /**
     * Get continents collection grouped by code.
     */
    protected function getContinents(): Collection
    {
        return Continent::all()->keyBy('code');
    }
}
