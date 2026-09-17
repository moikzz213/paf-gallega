<?php

namespace App\Support;

use App\Models\BusinessUnit;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Location;
use App\Models\Vendor;

final class MasterDataDefinition
{
    public static function find(string $entity): ?array
    {
        return self::all()[$entity] ?? null;
    }

    public static function all(): array
    {
        return [
            'vendors' => [
                'model' => Vendor::class,
                'table' => 'vendors',
                'label' => 'Vendors',
                'code_key' => 'vendor_code',
                'columns' => [
                    self::column('name', 'Name', true, 'Vendor name. Names may be shared by different vendor codes.', 34),
                    self::column('vendor_code', 'Vendor Code', false, 'Optional when unavailable; every entered vendor code must be unique.', 20),
                    self::column('credit_limit', 'Credit Limit', false, 'Non-negative amount. Blank defaults to 0.', 18),
                    self::column('credit_days', 'Credit Days', false, 'Whole number from 0 to 365. Blank defaults to 0.', 16),
                ],
            ],
            'customers' => [
                'model' => Customer::class,
                'table' => 'customers',
                'label' => 'Customers',
                'code_key' => 'customer_code',
                'columns' => [
                    self::column('name', 'Name', true, 'Customer name. Names may be shared by different customer codes.', 34),
                    self::column('customer_code', 'Customer Code', false, 'Optional when unavailable; every entered customer code must be unique.', 20),
                    self::column('credit_limit', 'Credit Limit', false, 'Non-negative amount. Blank defaults to 0.', 18),
                    self::column('credit_days', 'Credit Days', false, 'Whole number from 0 to 365. Blank defaults to 0.', 16),
                ],
            ],
            'business-units' => [
                'model' => BusinessUnit::class,
                'table' => 'business_units',
                'label' => 'Business Units',
                'code_key' => null,
                'columns' => [
                    self::column('name', 'Name', true, 'Business unit name. Must be unique.', 34),
                ],
            ],
            'departments' => [
                'model' => Department::class,
                'table' => 'departments',
                'label' => 'Departments',
                'code_key' => null,
                'columns' => [
                    self::column('name', 'Name', true, 'Department name. Must be unique.', 34),
                ],
            ],
            'locations' => [
                'model' => Location::class,
                'table' => 'locations',
                'label' => 'Locations',
                'code_key' => null,
                'columns' => [
                    self::column('name', 'Name', true, 'Location name. Must be unique.', 34),
                ],
            ],
            'currencies' => [
                'model' => Currency::class,
                'table' => 'currencies',
                'label' => 'Currencies',
                'code_key' => null,
                // Invoices store the currency as a plain string in a 3-character column, so the
                // name here *is* the code that gets written to the invoice.
                'name_rules' => ['string', 'size:3', 'alpha:ascii', 'uppercase'],
                'columns' => [
                    self::column('name', 'Name', true, 'Three-letter currency code in upper case, e.g. AED. Must be unique.', 34),
                    self::column('exchange_rate', 'Exchange Rate', false, 'How much '.self::baseCurrency().' one unit is worth, e.g. 3.6725 for USD. Approval thresholds are '.self::baseCurrency().' amounts, so a currency with no rate cannot be sent for approval. Leave blank for '.self::baseCurrency().' itself.', 22),
                ],
            ],
        ];
    }

    /** The currency approval thresholds are measured in — every other rate converts into it. */
    public static function baseCurrency(): string
    {
        return strtoupper((string) config('paf.base_currency', 'AED'));
    }

    /** Validation rules for an entity's name, excluding required/unique (callers add those). */
    public static function nameRules(array $definition): array
    {
        return $definition['name_rules'] ?? ['string', 'max:255'];
    }

    private static function column(
        string $key,
        string $heading,
        bool $required,
        string $description,
        int $width,
    ): array {
        return compact('key', 'heading', 'required', 'description', 'width');
    }
}
