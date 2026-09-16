<?php

namespace Database\Seeders;

use App\Enums\UnitType;
use App\Models\Category;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * The starting vocabulary a Pakistani kiryana store needs before it can enter
 * its first product. Everything here is editable afterwards — these are
 * defaults, not fixtures.
 *
 * Safe to run repeatedly: existing rows are matched by name and left alone.
 */
class CatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUnits();
        $this->seedCategories();

        $this->command?->info('Units and categories are ready.');
    }

    private function seedUnits(): void
    {
        /** @var array<int, array{0: string, 1: string, 2: UnitType}> $units */
        $units = [
            ['Piece', 'pc', UnitType::Count],
            ['Sachet', 'sct', UnitType::Count],
            ['Packet', 'pkt', UnitType::Count],
            ['Box', 'box', UnitType::Count],
            ['Carton', 'ctn', UnitType::Count],
            ['Bag', 'bag', UnitType::Count],
            ['Bottle', 'btl', UnitType::Count],
            ['Can', 'can', UnitType::Count],
            ['Dozen', 'dzn', UnitType::Count],
            ['Tray', 'try', UnitType::Count],
            ['Gram', 'g', UnitType::Weight],
            ['Kilogram', 'kg', UnitType::Weight],
            ['Millilitre', 'ml', UnitType::Volume],
            ['Litre', 'ltr', UnitType::Volume],
        ];

        foreach ($units as [$name, $shortName, $type]) {
            Unit::firstOrCreate(
                ['name' => $name],
                ['short_name' => $shortName, 'type' => $type],
            );
        }
    }

    private function seedCategories(): void
    {
        /** @var array<string, array{0: string, 1: array<string, string>}> $categories */
        $categories = [
            'Grocery & Staples' => ['گروسری', [
                'Flour & Rice' => 'آٹا اور چاول',
                'Pulses & Beans' => 'دالیں',
                'Sugar & Salt' => 'چینی اور نمک',
            ]],
            'Cooking Oil & Ghee' => ['تیل اور گھی', []],
            'Spices & Masala' => ['مصالحہ جات', []],
            'Tea & Coffee' => ['چائے اور کافی', []],
            'Beverages' => ['مشروبات', [
                'Soft Drinks' => 'کولڈ ڈرنکس',
                'Juices & Water' => 'جوس اور پانی',
            ]],
            'Snacks & Confectionery' => ['سنیکس اور مٹھائی', [
                'Biscuits' => 'بسکٹ',
                'Chips & Namkeen' => 'چپس اور نمکین',
                'Chocolates & Sweets' => 'چاکلیٹ',
            ]],
            'Dairy & Eggs' => ['ڈیری اور انڈے', []],
            'Bakery' => ['بیکری', []],
            'Frozen Food' => ['فروزن فوڈ', []],
            'Personal Care' => ['ذاتی نگہداشت', [
                'Soap & Shampoo' => 'صابن اور شیمپو',
                'Oral Care' => 'دانتوں کی صفائی',
            ]],
            'Home Care & Cleaning' => ['صفائی کا سامان', [
                'Detergents' => 'واشنگ پاؤڈر',
                'Cleaners & Fresheners' => 'کلینر',
            ]],
            'Baby Care' => ['بچوں کا سامان', []],
            'Stationery' => ['اسٹیشنری', []],
            'Cigarettes & Matches' => ['سگریٹ اور ماچس', []],
        ];

        foreach ($categories as $name => [$nameUr, $children]) {
            $parent = Category::firstOrCreate(
                ['parent_id' => null, 'name' => $name],
                ['name_ur' => $nameUr, 'is_active' => true],
            );

            foreach ($children as $childName => $childNameUr) {
                Category::firstOrCreate(
                    ['parent_id' => $parent->id, 'name' => $childName],
                    ['name_ur' => $childNameUr, 'is_active' => true],
                );
            }
        }
    }
}
