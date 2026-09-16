<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The products almost every kiryana and supermarket in Pakistan carries,
 * put on the list in one press so a new shop does not start from nothing.
 *
 * What goes in is the name, the category, the brand, how each item is
 * packed and a starting price. What does not go in is stock or barcodes.
 * Stock arrives with the first delivery, the way it really came in, or the
 * books would show goods the shop never bought. Barcodes are scanned off the
 * packets on the shelf, because a made-up one would ring up the wrong thing.
 *
 * It can only be done once. After that the button says so and does nothing,
 * so a second press cannot bring back items the shop has since removed or
 * put the old prices back over the ones the shopkeeper has set.
 */
class StarterCatalogueService
{
    /** Where the date it was done is kept. */
    public const SETTING = 'catalogue.seeded_at';

    public function __construct(private readonly PackagingService $packaging) {}

    public function isInstalled(): bool
    {
        return $this->installedAt() !== null;
    }

    public function installedAt(): ?CarbonImmutable
    {
        $value = (string) Setting::read(self::SETTING, '');

        return $value === '' ? null : CarbonImmutable::parse($value);
    }

    /**
     * Put the list in, and answer how many products were added.
     *
     * An item already in the shop — by the same code or the same name — is
     * left exactly as it is. Nothing happens at all once the list has been
     * put in before.
     */
    public function install(): int
    {
        return DB::transaction(function (): int {
            /* Once it has been done, it is never done again. */
            $done = Setting::query()->where('key', self::SETTING)->lockForUpdate()->value('value');

            if ($done !== null && $done !== '') {
                return 0;
            }

            /* The units and categories the list is written in. Safe to run on
               a shop that already has them. */
            app(CatalogueSeeder::class)->run();

            $units = Unit::pluck('id', 'name');
            $categories = Category::pluck('id', 'name');
            $names = Product::pluck('name')->map(fn (string $name): string => mb_strtolower($name))->flip();
            $skus = Product::pluck('sku')->flip();
            $added = 0;

            foreach ($this->products() as $row) {
                if ($names->has(mb_strtolower($row['name'])) || $skus->has($row['sku'])) {
                    continue;
                }

                $product = Product::create([
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'category_id' => $categories[$row['category']] ?? null,
                    'brand_id' => $row['brand'] === null ? null : $this->brandId($row['brand']),
                    'base_unit_id' => $units[$row['base']],
                    'tax_rate' => $row['tax'],
                    'is_weighted' => $row['base'] === 'Gram',
                    'is_active' => true,
                ]);

                $this->packaging->sync($product, $this->levels($row, $units));

                $added++;
            }

            Setting::write(self::SETTING, now()->toIso8601String(), 'catalogue');

            return $added;
        });
    }

    /**
     * Turn one row into the packaging levels PackagingService wants: the
     * piece itself, then each size it comes packed in, counted in the size
     * below it. A bigger pack is priced at what its pieces add up to, and
     * the shopkeeper trims that where they give a carton rate.
     *
     * @param  array{sku: string, name: string, category: string, brand: string|null, base: string, price: int, tax: string, packs: array<int, array{0: string, 1: int}>}  $row
     * @param  Collection<string, int>  $units
     * @return array<int, array{unit_id: int, parent_unit_id: int|null, qty_per_parent: int, sale_price_paisa: int, mrp_paisa: null, is_default_sale: bool, is_default_purchase: bool, barcodes: array<int, string>}>
     */
    private function levels(array $row, Collection $units): array
    {
        $loose = $row['base'] === 'Gram';

        $levels = [[
            'unit_id' => $units[$row['base']],
            'parent_unit_id' => null,
            'qty_per_parent' => 1,
            'sale_price_paisa' => $row['price'],
            'mrp_paisa' => null,
            'is_default_sale' => ! $loose || $row['packs'] === [],
            'is_default_purchase' => $row['packs'] === [],
            'barcodes' => [],
        ]];

        $parent = $row['base'];
        $pieces = 1;
        $last = count($row['packs']) - 1;

        foreach ($row['packs'] as $index => [$unitName, $perParent]) {
            $pieces *= $perParent;

            $levels[] = [
                'unit_id' => $units[$unitName],
                'parent_unit_id' => $units[$parent],
                'qty_per_parent' => $perParent,
                'sale_price_paisa' => $row['price'] * $pieces,
                'mrp_paisa' => null,
                /* Loose goods are rung up by the kilo, never by the gram. */
                'is_default_sale' => $loose && $index === 0,
                'is_default_purchase' => $index === $last,
                'barcodes' => [],
            ];

            $parent = $unitName;
        }

        return $levels;
    }

    private function brandId(string $name): int
    {
        return Brand::firstOrCreate(['name' => $name], ['is_active' => true])->getKey();
    }

    /**
     * The list itself.
     *
     * Each line is: code, name, category, brand, the smallest piece sold,
     * its price in rupees (per kilo for loose goods), the tax rate, and the
     * bigger packs it comes in. Prices are ordinary shelf prices and only a
     * starting point — every area and every month is different, and the
     * shopkeeper sets their own. Tax is 0 on unpacked staples, bread, eggs
     * and salt, and 18 on packed branded goods.
     *
     * @return array<int, array{sku: string, name: string, category: string, brand: string|null, base: string, price: int, tax: string, packs: array<int, array{0: string, 1: int}>}>
     */
    public function products(): array
    {
        $kg = [['Kilogram', 1000]];
        $ctn6 = [['Carton', 6]];
        $ctn12 = [['Carton', 12]];
        $ctn24 = [['Carton', 24]];
        $box12 = [['Box', 12]];
        $box24 = [['Box', 24]];
        $box30 = [['Box', 30]];
        $boxCarton = [['Box', 12], ['Carton', 6]];
        $ticky = [['Box', 24], ['Carton', 12]];
        $soap = [['Box', 12], ['Carton', 6]];
        $sachets = [['Packet', 16], ['Carton', 24]];
        $cigarettes = [['Packet', 20], ['Carton', 10]];

        return array_map(fn (array $row): array => [
            'sku' => $row[0],
            'name' => $row[1],
            'category' => $row[2],
            'brand' => $row[3],
            'base' => $row[4],
            /* Loose goods are kept by the gram, so the kilo price is shared out. */
            'price' => $row[4] === 'Gram' ? intdiv($row[5] * 100, 1000) : $row[5] * 100,
            'tax' => $row[6],
            'packs' => $row[7],
        ], [
            // Flour and rice
            ['ATTA-CHAKKI', 'Chakki Atta (loose)', 'Flour & Rice', null, 'Gram', 140, '0', $kg],
            ['ATTA-FINE', 'Fine Atta (loose)', 'Flour & Rice', null, 'Gram', 130, '0', $kg],
            ['MAIDA-LOOSE', 'Maida (loose)', 'Flour & Rice', null, 'Gram', 150, '0', $kg],
            ['SOOJI-LOOSE', 'Sooji (loose)', 'Flour & Rice', null, 'Gram', 180, '0', $kg],
            ['BESAN-LOOSE', 'Besan (loose)', 'Flour & Rice', null, 'Gram', 320, '0', $kg],
            ['RICE-SUPER', 'Super Kernel Basmati Rice (loose)', 'Flour & Rice', null, 'Gram', 420, '0', $kg],
            ['RICE-SELLA', 'Sella Basmati Rice (loose)', 'Flour & Rice', null, 'Gram', 380, '0', $kg],
            ['RICE-IRRI', 'Irri-6 Rice (loose)', 'Flour & Rice', null, 'Gram', 240, '0', $kg],
            ['RICE-TOTA', 'Broken Rice / Tota (loose)', 'Flour & Rice', null, 'Gram', 220, '0', $kg],
            ['SUNRIDGE-5KG', 'Sunridge Fine Atta 5 kg bag', 'Flour & Rice', 'Sunridge', 'Bag', 890, '0', []],
            ['SUNRIDGE-10KG', 'Sunridge Fine Atta 10 kg bag', 'Flour & Rice', 'Sunridge', 'Bag', 1750, '0', []],
            ['FAUJI-ATTA-10KG', 'Fauji Chakki Atta 10 kg bag', 'Flour & Rice', 'Fauji', 'Bag', 1700, '0', []],
            ['GUARD-RICE-1KG', 'Guard Basmati Rice 1 kg', 'Flour & Rice', 'Guard', 'Packet', 520, '18', [['Carton', 10]]],
            ['FALAK-SELLA-5KG', 'Falak Sella Rice 5 kg bag', 'Flour & Rice', 'Falak', 'Bag', 2300, '18', []],

            // Pulses and beans
            ['DAL-CHANA', 'Chana Daal (loose)', 'Pulses & Beans', null, 'Gram', 330, '0', $kg],
            ['DAL-MASOOR', 'Masoor Daal (loose)', 'Pulses & Beans', null, 'Gram', 380, '0', $kg],
            ['DAL-MOONG', 'Moong Daal (loose)', 'Pulses & Beans', null, 'Gram', 360, '0', $kg],
            ['DAL-MASH', 'Mash Daal (loose)', 'Pulses & Beans', null, 'Gram', 520, '0', $kg],
            ['MASOOR-SABUT', 'Sabut Masoor (loose)', 'Pulses & Beans', null, 'Gram', 360, '0', $kg],
            ['MOONG-SABUT', 'Sabut Moong (loose)', 'Pulses & Beans', null, 'Gram', 340, '0', $kg],
            ['CHANA-KALA', 'Kala Chana (loose)', 'Pulses & Beans', null, 'Gram', 300, '0', $kg],
            ['CHANA-SAFED', 'Safed Chana (loose)', 'Pulses & Beans', null, 'Gram', 420, '0', $kg],
            ['LOBIA-LOOSE', 'Lobia (loose)', 'Pulses & Beans', null, 'Gram', 450, '0', $kg],
            ['RAJMA-LOOSE', 'Red Kidney Beans (loose)', 'Pulses & Beans', null, 'Gram', 520, '0', $kg],

            // Sugar and salt
            ['SUGAR-LOOSE', 'Sugar (loose)', 'Sugar & Salt', null, 'Gram', 190, '0', $kg],
            ['GUR-LOOSE', 'Gur (loose)', 'Sugar & Salt', null, 'Gram', 280, '0', $kg],
            ['SHAKKAR-LOOSE', 'Shakkar (loose)', 'Sugar & Salt', null, 'Gram', 300, '0', $kg],
            ['NATIONAL-SALT-800', 'National Iodized Salt 800 g', 'Sugar & Salt', 'National', 'Packet', 60, '0', [['Carton', 25]]],
            ['PINK-SALT-800', 'Himalayan Pink Salt 800 g', 'Sugar & Salt', null, 'Packet', 120, '0', [['Carton', 25]]],

            // Cooking oil and ghee
            ['DALDA-OIL-1L', 'Dalda Cooking Oil 1 litre', 'Cooking Oil & Ghee', 'Dalda', 'Bottle', 620, '18', $ctn12],
            ['DALDA-OIL-5L', 'Dalda Cooking Oil 5 litre', 'Cooking Oil & Ghee', 'Dalda', 'Bottle', 3150, '18', [['Carton', 4]]],
            ['DALDA-GHEE-1K', 'Dalda Banaspati Ghee 1 kg', 'Cooking Oil & Ghee', 'Dalda', 'Packet', 640, '18', $ctn12],
            ['DALDA-GHEE-5K', 'Dalda Banaspati Ghee 5 kg tin', 'Cooking Oil & Ghee', 'Dalda', 'Can', 3100, '18', [['Carton', 4]]],
            ['SUFI-OIL-5L', 'Sufi Cooking Oil 5 litre', 'Cooking Oil & Ghee', 'Sufi', 'Bottle', 3050, '18', [['Carton', 4]]],
            ['SUFI-GHEE-1K', 'Sufi Banaspati Ghee 1 kg', 'Cooking Oil & Ghee', 'Sufi', 'Packet', 600, '18', $ctn12],
            ['HABIB-OIL-1L', 'Habib Cooking Oil 1 litre', 'Cooking Oil & Ghee', 'Habib', 'Bottle', 600, '18', $ctn12],
            ['HABIB-GHEE-1K', 'Habib Banaspati Ghee 1 kg', 'Cooking Oil & Ghee', 'Habib', 'Packet', 610, '18', $ctn12],
            ['EVA-OIL-1L', 'Eva Cooking Oil 1 litre', 'Cooking Oil & Ghee', 'Eva', 'Bottle', 590, '18', $ctn12],
            ['KISAN-OIL-1L', 'Kisan Cooking Oil 1 litre', 'Cooking Oil & Ghee', 'Kisan', 'Bottle', 580, '18', $ctn12],
            ['SEASONS-CANOLA-1L', 'Seasons Canola Oil 1 litre', 'Cooking Oil & Ghee', 'Seasons', 'Bottle', 650, '18', $ctn12],
            ['MEEZAN-GHEE-1K', 'Meezan Banaspati Ghee 1 kg', 'Cooking Oil & Ghee', 'Meezan', 'Packet', 590, '18', $ctn12],
            ['SASSO-OLIVE-250', 'Sasso Olive Oil 250 ml', 'Cooking Oil & Ghee', 'Sasso', 'Bottle', 1300, '18', $box12],

            // Spices and masala
            ['SHAN-BIRYANI', 'Shan Bombay Biryani Masala 60 g', 'Spices & Masala', 'Shan', 'Sachet', 160, '18', $boxCarton],
            ['SHAN-SINDHI-BIRYANI', 'Shan Sindhi Biryani Masala 60 g', 'Spices & Masala', 'Shan', 'Sachet', 160, '18', $boxCarton],
            ['SHAN-NIHARI', 'Shan Nihari Masala 60 g', 'Spices & Masala', 'Shan', 'Sachet', 160, '18', $boxCarton],
            ['SHAN-QORMA', 'Shan Qorma Masala 50 g', 'Spices & Masala', 'Shan', 'Sachet', 160, '18', $boxCarton],
            ['SHAN-KARAHI', 'Shan Chicken Karahi Masala 50 g', 'Spices & Masala', 'Shan', 'Sachet', 160, '18', $boxCarton],
            ['SHAN-TIKKA', 'Shan Tikka Boti Masala 50 g', 'Spices & Masala', 'Shan', 'Sachet', 160, '18', $boxCarton],
            ['SHAN-ACHAR-GOSHT', 'Shan Achar Gosht Masala 50 g', 'Spices & Masala', 'Shan', 'Sachet', 160, '18', $boxCarton],
            ['SHAN-HALEEM', 'Shan Shahi Haleem Mix 300 g', 'Spices & Masala', 'Shan', 'Packet', 300, '18', $box12],
            ['SHAN-GARLIC-310', 'Shan Garlic Paste 310 g', 'Spices & Masala', 'Shan', 'Bottle', 360, '18', $box12],
            ['SHAN-GINGER-310', 'Shan Ginger Paste 310 g', 'Spices & Masala', 'Shan', 'Bottle', 360, '18', $box12],
            ['NATIONAL-CHAAT', 'National Chaat Masala 100 g', 'Spices & Masala', 'National', 'Sachet', 195, '18', $box12],
            ['NATIONAL-BIRYANI', 'National Bombay Biryani Masala 42 g', 'Spices & Masala', 'National', 'Sachet', 110, '18', $boxCarton],
            ['NATIONAL-KARAHI', 'National Karahi Gosht Masala 44 g', 'Spices & Masala', 'National', 'Sachet', 110, '18', $boxCarton],
            ['NATIONAL-TIKKA', 'National Tikka Masala 44 g', 'Spices & Masala', 'National', 'Sachet', 110, '18', $boxCarton],
            ['NATIONAL-CHILLI-200', 'National Red Chilli Powder 200 g', 'Spices & Masala', 'National', 'Packet', 360, '18', $box12],
            ['NATIONAL-HALDI-200', 'National Haldi Powder 200 g', 'Spices & Masala', 'National', 'Packet', 250, '18', $box12],
            ['NATIONAL-DHANIA-200', 'National Dhania Powder 200 g', 'Spices & Masala', 'National', 'Packet', 220, '18', $box12],
            ['NATIONAL-GARAM-50', 'National Garam Masala Powder 50 g', 'Spices & Masala', 'National', 'Packet', 180, '18', $box12],
            ['ZEERA-LOOSE', 'Zeera (loose)', 'Spices & Masala', null, 'Gram', 2200, '0', $kg],
            ['KALIMIRCH-LOOSE', 'Kali Mirch (loose)', 'Spices & Masala', null, 'Gram', 2800, '0', $kg],
            ['LAL-MIRCH-LOOSE', 'Sabut Lal Mirch (loose)', 'Spices & Masala', null, 'Gram', 900, '0', $kg],
            ['HALDI-LOOSE', 'Haldi (loose)', 'Spices & Masala', null, 'Gram', 700, '0', $kg],
            ['SAUNF-LOOSE', 'Saunf (loose)', 'Spices & Masala', null, 'Gram', 900, '0', $kg],
            ['AJWAIN-LOOSE', 'Ajwain (loose)', 'Spices & Masala', null, 'Gram', 1100, '0', $kg],
            ['LAUNG-LOOSE', 'Laung (loose)', 'Spices & Masala', null, 'Gram', 4000, '0', $kg],
            ['ELAICHI-LOOSE', 'Sabz Elaichi (loose)', 'Spices & Masala', null, 'Gram', 9000, '0', $kg],

            // Tea and coffee
            ['TAPAL-190', 'Tapal Danedar 190 g', 'Tea & Coffee', 'Tapal', 'Packet', 580, '18', $ctn24],
            ['TAPAL-900', 'Tapal Danedar 900 g', 'Tea & Coffee', 'Tapal', 'Packet', 2450, '18', $ctn6],
            ['TAPAL-35', 'Tapal Danedar 35 g pouch', 'Tea & Coffee', 'Tapal', 'Sachet', 120, '18', $box12],
            ['TAPAL-FAMILY-190', 'Tapal Family Mixture 190 g', 'Tea & Coffee', 'Tapal', 'Packet', 560, '18', $ctn24],
            ['TAPAL-GREEN-30', 'Tapal Jasmine Green Tea, 30 bags', 'Tea & Coffee', 'Tapal', 'Box', 380, '18', $ctn12],
            ['LIPTON-190', 'Lipton Yellow Label 190 g', 'Tea & Coffee', 'Lipton', 'Packet', 780, '18', $ctn24],
            ['LIPTON-475', 'Lipton Yellow Label 475 g', 'Tea & Coffee', 'Lipton', 'Packet', 1850, '18', $ctn12],
            ['LIPTON-GREEN-30', 'Lipton Green Tea, 30 bags', 'Tea & Coffee', 'Lipton', 'Box', 450, '18', $ctn12],
            ['VITAL-190', 'Vital Tea 190 g', 'Tea & Coffee', 'Vital', 'Packet', 500, '18', $ctn24],
            ['SUPREME-190', 'Supreme Tea 190 g', 'Tea & Coffee', 'Supreme', 'Packet', 560, '18', $ctn24],
            ['NESCAFE-50', 'Nescafe Classic 50 g', 'Tea & Coffee', 'Nescafe', 'Bottle', 1250, '18', [['Box', 6]]],
            ['NESCAFE-3IN1', 'Nescafe 3 in 1 sachet', 'Tea & Coffee', 'Nescafe', 'Sachet', 40, '18', [['Packet', 24], ['Carton', 12]]],

            // Soft drinks
            ['COKE-500', 'Coca-Cola 500 ml', 'Soft Drinks', 'Coca-Cola', 'Bottle', 140, '18', $ctn12],
            ['COKE-1500', 'Coca-Cola 1.5 litre', 'Soft Drinks', 'Coca-Cola', 'Bottle', 220, '18', $ctn6],
            ['SPRITE-1500', 'Sprite 1.5 litre', 'Soft Drinks', 'Sprite', 'Bottle', 220, '18', $ctn6],
            ['FANTA-1500', 'Fanta 1.5 litre', 'Soft Drinks', 'Fanta', 'Bottle', 220, '18', $ctn6],
            ['PEPSI-345', 'Pepsi 345 ml can', 'Soft Drinks', 'Pepsi', 'Can', 110, '18', [['Packet', 6], ['Carton', 4]]],
            ['PEPSI-500', 'Pepsi 500 ml', 'Soft Drinks', 'Pepsi', 'Bottle', 140, '18', $ctn12],
            ['PEPSI-1500', 'Pepsi 1.5 litre', 'Soft Drinks', 'Pepsi', 'Bottle', 220, '18', $ctn6],
            ['PEPSI-2250', 'Pepsi 2.25 litre', 'Soft Drinks', 'Pepsi', 'Bottle', 300, '18', $ctn6],
            ['7UP-1500', '7Up 1.5 litre', 'Soft Drinks', '7Up', 'Bottle', 220, '18', $ctn6],
            ['MIRINDA-1500', 'Mirinda 1.5 litre', 'Soft Drinks', 'Mirinda', 'Bottle', 220, '18', $ctn6],
            ['DEW-1500', 'Mountain Dew 1.5 litre', 'Soft Drinks', 'Mountain Dew', 'Bottle', 220, '18', $ctn6],
            ['GOURMET-COLA-1500', 'Gourmet Cola 1.5 litre', 'Soft Drinks', 'Gourmet', 'Bottle', 180, '18', $ctn6],
            ['PAKOLA-345', 'Pakola Ice Cream Soda 345 ml can', 'Soft Drinks', 'Pakola', 'Can', 120, '18', $ctn24],
            ['STING-500', 'Sting Energy Drink 500 ml', 'Soft Drinks', 'Sting', 'Bottle', 140, '18', $ctn12],
            ['REDBULL-250', 'Red Bull 250 ml can', 'Soft Drinks', 'Red Bull', 'Can', 550, '18', $ctn24],

            // Juices, squash and water
            ['NESTLE-500', 'Nestle Pure Life 500 ml', 'Juices & Water', 'Nestle', 'Bottle', 70, '18', [['Packet', 12]]],
            ['NESTLE-1500', 'Nestle Pure Life 1.5 litre', 'Juices & Water', 'Nestle', 'Bottle', 140, '18', [['Packet', 6]]],
            ['AQUAFINA-500', 'Aquafina 500 ml', 'Juices & Water', 'Aquafina', 'Bottle', 70, '18', [['Packet', 12]]],
            ['AQUAFINA-1500', 'Aquafina 1.5 litre', 'Juices & Water', 'Aquafina', 'Bottle', 130, '18', [['Packet', 6]]],
            ['SLICE-200', 'Slice Mango Juice 200 ml', 'Juices & Water', 'Slice', 'Packet', 70, '18', $box24],
            ['SLICE-1000', 'Slice Mango Juice 1 litre', 'Juices & Water', 'Slice', 'Packet', 320, '18', $ctn12],
            ['SHEZAN-MANGO-250', 'Shezan Mango Juice 250 ml', 'Juices & Water', 'Shezan', 'Packet', 70, '18', $box24],
            ['NESTLE-NECTAR-200', 'Nestle Mango Nectar 200 ml', 'Juices & Water', 'Nestle', 'Packet', 70, '18', $box24],
            ['FROOTO-200', 'Frooto Mango Juice 200 ml', 'Juices & Water', 'Frooto', 'Packet', 50, '18', $box24],
            ['FRUITA-1000', 'Nestle Fruita Vitals Red Grape 1 litre', 'Juices & Water', 'Nestle', 'Packet', 380, '18', $ctn12],
            ['TANG-375', 'Tang Orange 375 g', 'Juices & Water', 'Tang', 'Packet', 750, '18', $ctn12],
            ['ROOHAFZA-800', 'Rooh Afza 800 ml', 'Juices & Water', 'Hamdard', 'Bottle', 520, '18', $ctn12],
            ['JAMESHIRIN-800', 'Jam-e-Shirin 800 ml', 'Juices & Water', 'Qarshi', 'Bottle', 480, '18', $ctn12],

            // Biscuits
            ['SOOPER-FAMILY', 'Sooper Biscuit family pack', 'Biscuits', 'EBM', 'Packet', 150, '18', $boxCarton],
            ['SOOPER-TICKY', 'Sooper Biscuit ticky pack', 'Biscuits', 'EBM', 'Packet', 30, '18', $ticky],
            ['GALA-TICKY', 'Gala Biscuit ticky pack', 'Biscuits', 'LU', 'Packet', 30, '18', $ticky],
            ['PRINCE-20', 'Prince Biscuit Rs 20 pack', 'Biscuits', 'LU', 'Packet', 20, '18', $box24],
            ['PRINCE-FAMILY', 'Prince Biscuit family pack', 'Biscuits', 'LU', 'Packet', 150, '18', $box12],
            ['CANDI-TICKY', 'Candi Biscuit ticky pack', 'Biscuits', 'LU', 'Packet', 30, '18', $ticky],
            ['TUC-TICKY', 'Tuc Biscuit ticky pack', 'Biscuits', 'LU', 'Packet', 30, '18', $ticky],
            ['ZEERA-PLUS-TICKY', 'Zeera Plus Biscuit ticky pack', 'Biscuits', 'LU', 'Packet', 30, '18', $ticky],
            ['OREO-HALF', 'Oreo half roll', 'Biscuits', 'LU', 'Packet', 50, '18', $box12],
            ['TIGER-10', 'Tiger Biscuit Rs 10 pack', 'Biscuits', 'LU', 'Packet', 10, '18', $box24],
            ['PEANUT-PIK-TICKY', 'Peek Freans Peanut Pik ticky pack', 'Biscuits', 'Peek Freans', 'Packet', 30, '18', $ticky],
            ['RIO-HALF', 'Peek Freans Rio half roll', 'Biscuits', 'Peek Freans', 'Packet', 30, '18', $box24],
            ['MARIE-PF', 'Peek Freans Marie biscuit', 'Biscuits', 'Peek Freans', 'Packet', 40, '18', $box24],
            ['PARTY-PF', 'Peek Freans Party biscuit', 'Biscuits', 'Peek Freans', 'Packet', 30, '18', $box24],
            ['BISCONNI-CHOCCHIP', 'Bisconni Chocolate Chip Cookies', 'Biscuits', 'Bisconni', 'Packet', 60, '18', $box12],
            ['BISCONNI-COCOMO', 'Bisconni Cocomo', 'Biscuits', 'Bisconni', 'Packet', 20, '18', $box24],

            // Chips and namkeen
            ['LAYS-MASALA', 'Lays Masala 40 g', 'Chips & Namkeen', 'Lays', 'Packet', 90, '18', $box24],
            ['LAYS-CLASSIC', 'Lays Classic Salted 40 g', 'Chips & Namkeen', 'Lays', 'Packet', 90, '18', $box24],
            ['LAYS-CHEESE', 'Lays French Cheese 40 g', 'Chips & Namkeen', 'Lays', 'Packet', 90, '18', $box24],
            ['LAYS-MASALA-70', 'Lays Masala 70 g', 'Chips & Namkeen', 'Lays', 'Packet', 150, '18', $box12],
            ['LAYS-WAVY', 'Lays Wavy 48 g', 'Chips & Namkeen', 'Lays', 'Packet', 110, '18', $box24],
            ['KURKURE-30', 'Kurkure Chatpata 30 g', 'Chips & Namkeen', 'Kurkure', 'Packet', 50, '18', $box30],
            ['KURKURE-RED-30', 'Kurkure Red Chilli 30 g', 'Chips & Namkeen', 'Kurkure', 'Packet', 50, '18', $box30],
            ['CHEETOS-26', 'Cheetos Flamin Hot 26 g', 'Chips & Namkeen', 'Cheetos', 'Packet', 50, '18', $box30],
            ['SUPERCRISP-BBQ', 'Super Crisp BBQ 30 g', 'Chips & Namkeen', 'Super Crisp', 'Packet', 50, '18', $box30],
            ['SLANTY-20', 'Kolson Slanty 20 g', 'Chips & Namkeen', 'Kolson', 'Packet', 30, '18', $box30],
            ['NIMKO-MIX-LOOSE', 'Mix Nimko (loose)', 'Chips & Namkeen', null, 'Gram', 900, '0', $kg],
            ['DALMOTH-LOOSE', 'Dalmoth (loose)', 'Chips & Namkeen', null, 'Gram', 800, '0', $kg],

            // Chocolates and sweets
            ['DAIRYMILK-35', 'Cadbury Dairy Milk 35 g', 'Chocolates & Sweets', 'Cadbury', 'Piece', 160, '18', $box24],
            ['DAIRYMILK-90', 'Cadbury Dairy Milk 90 g', 'Chocolates & Sweets', 'Cadbury', 'Piece', 480, '18', $box12],
            ['PERK-20', 'Cadbury Perk', 'Chocolates & Sweets', 'Cadbury', 'Piece', 20, '18', $box24],
            ['KITKAT-2F', 'KitKat 2 finger', 'Chocolates & Sweets', 'Nestle', 'Piece', 80, '18', $box24],
            ['SNICKERS-50', 'Snickers 50 g', 'Chocolates & Sweets', 'Mars', 'Piece', 250, '18', $box24],
            ['MARS-51', 'Mars bar 51 g', 'Chocolates & Sweets', 'Mars', 'Piece', 250, '18', $box24],
            ['BOUNTY-57', 'Bounty 57 g', 'Chocolates & Sweets', 'Mars', 'Piece', 250, '18', $box24],
            ['POLO-ROLL', 'Polo mint roll', 'Chocolates & Sweets', 'Nestle', 'Piece', 20, '18', $box24],
            ['MENTOS-ROLL', 'Mentos mint roll', 'Chocolates & Sweets', 'Mentos', 'Piece', 40, '18', [['Box', 20]]],
            ['CHILLI-MILLI', 'Chilli Milli jelly', 'Chocolates & Sweets', 'Hilal', 'Piece', 20, '18', [['Box', 50]]],
            ['LOLLIPOP-10', 'Lollipop', 'Chocolates & Sweets', 'Hilal', 'Piece', 10, '18', [['Packet', 50]]],
            ['ECLAIRS', 'Mitchell\'s Chocolate Eclairs toffee', 'Chocolates & Sweets', 'Mitchell\'s', 'Piece', 5, '18', [['Box', 100]]],

            // Dairy and eggs
            ['OLPERS-1L', 'Olpers Milk 1 litre', 'Dairy & Eggs', 'Olpers', 'Packet', 320, '18', $ctn12],
            ['OLPERS-250', 'Olpers Milk 250 ml', 'Dairy & Eggs', 'Olpers', 'Packet', 100, '18', $ctn24],
            ['MILKPAK-1L', 'Nestle Milkpak 1 litre', 'Dairy & Eggs', 'Nestle', 'Packet', 330, '18', $ctn12],
            ['MILKPAK-250', 'Nestle Milkpak 250 ml', 'Dairy & Eggs', 'Nestle', 'Packet', 100, '18', $ctn24],
            ['HALEEB-1L', 'Haleeb Milk 1 litre', 'Dairy & Eggs', 'Haleeb', 'Packet', 300, '18', $ctn12],
            ['TARANG-1L', 'Tarang Tea Whitener 1 litre', 'Dairy & Eggs', 'Tarang', 'Packet', 360, '18', $ctn12],
            ['EVERYDAY-390', 'Nestle Everyday 390 g', 'Dairy & Eggs', 'Nestle', 'Packet', 1250, '18', $ctn24],
            ['NIDO-390', 'Nestle Nido Fortigrow 390 g', 'Dairy & Eggs', 'Nestle', 'Packet', 1500, '18', $ctn24],
            ['OLPERS-CREAM-200', 'Olpers Cream 200 ml', 'Dairy & Eggs', 'Olpers', 'Packet', 290, '18', $ctn24],
            ['NESTLE-YOGURT-400', 'Nestle Yogurt 400 g', 'Dairy & Eggs', 'Nestle', 'Piece', 180, '18', $box12],
            ['DAHI-LOOSE', 'Dahi (loose)', 'Dairy & Eggs', null, 'Gram', 260, '0', $kg],
            ['NURPUR-BUTTER', 'Nurpur Butter 200 g', 'Dairy & Eggs', 'Nurpur', 'Packet', 640, '18', $box12],
            ['BLUEBAND-235', 'Blue Band Margarine 235 g', 'Dairy & Eggs', 'Blue Band', 'Packet', 350, '18', $box24],
            ['ADAMS-SLICES-200', 'Adams Cheese Slices 200 g', 'Dairy & Eggs', 'Adams', 'Packet', 850, '18', $box12],
            ['EGGS', 'Farm Eggs', 'Dairy & Eggs', null, 'Piece', 35, '0', [['Tray', 30]]],

            // Bakery
            ['DAWN-BREAD', 'Dawn Bread large', 'Bakery', 'Dawn', 'Packet', 210, '0', []],
            ['DAWN-BREAD-SMALL', 'Dawn Bread small', 'Bakery', 'Dawn', 'Packet', 130, '0', []],
            ['DAWN-BROWN', 'Dawn Brown Bread', 'Bakery', 'Dawn', 'Packet', 250, '0', []],
            ['DAWN-MILKY', 'Dawn Milky Bread', 'Bakery', 'Dawn', 'Packet', 220, '0', []],
            ['DAWN-BUNS-4', 'Dawn Burger Buns, pack of 4', 'Bakery', 'Dawn', 'Packet', 180, '0', []],
            ['RUSK-MILK', 'Peek Freans Milk Rusk', 'Bakery', 'Peek Freans', 'Packet', 330, '18', [['Box', 8]]],
            ['CAKE-RUSK-LOOSE', 'Cake Rusk (loose)', 'Bakery', null, 'Gram', 900, '0', $kg],

            // Frozen food
            ['KN-NUGGETS-1KG', 'K&N\'s Chicken Nuggets 1 kg', 'Frozen Food', 'K&N\'s', 'Packet', 2200, '18', $ctn6],
            ['KN-NUGGETS-500', 'K&N\'s Chicken Nuggets 500 g', 'Frozen Food', 'K&N\'s', 'Packet', 1150, '18', $ctn12],
            ['KN-SEEKH-540', 'K&N\'s Chicken Seekh Kabab 540 g', 'Frozen Food', 'K&N\'s', 'Packet', 1200, '18', $ctn12],
            ['SABROSO-NUGGETS-500', 'Sabroso Chicken Nuggets 500 g', 'Frozen Food', 'Sabroso', 'Packet', 1050, '18', $ctn12],
            ['DAWN-PARATHA-5', 'Dawn Plain Paratha, 5 pieces', 'Frozen Food', 'Dawn', 'Packet', 380, '18', $ctn12],
            ['DAWN-LACHHA-5', 'Dawn Lachha Paratha, 5 pieces', 'Frozen Food', 'Dawn', 'Packet', 450, '18', $ctn12],
            ['MENU-SAMOSA-12', 'Menu Chicken Samosa, 12 pieces', 'Frozen Food', 'Menu', 'Packet', 650, '18', $ctn12],
            ['WALLS-CORNETTO', 'Wall\'s Cornetto Chocolate', 'Frozen Food', 'Wall\'s', 'Piece', 150, '18', $box24],
            ['WALLS-FEAST', 'Wall\'s Feast', 'Frozen Food', 'Wall\'s', 'Piece', 100, '18', $box24],
            ['IGLOO-CUP', 'Igloo Vanilla Cup', 'Frozen Food', 'Igloo', 'Piece', 60, '18', $box24],

            // Soap and shampoo
            ['SAFEGUARD-130', 'Safeguard Soap 130 g', 'Soap & Shampoo', 'Safeguard', 'Piece', 230, '18', $soap],
            ['LUX-130', 'Lux Soap 130 g', 'Soap & Shampoo', 'Lux', 'Piece', 190, '18', $soap],
            ['LIFEBUOY-115', 'Lifebuoy Soap 115 g', 'Soap & Shampoo', 'Lifebuoy', 'Piece', 150, '18', $soap],
            ['DETTOL-SOAP-115', 'Dettol Soap 115 g', 'Soap & Shampoo', 'Dettol', 'Piece', 210, '18', $soap],
            ['CAPRI-115', 'Capri Soap 115 g', 'Soap & Shampoo', 'Capri', 'Piece', 140, '18', $soap],
            ['DOVE-SOAP-100', 'Dove Soap 100 g', 'Soap & Shampoo', 'Dove', 'Piece', 380, '18', $box12],
            ['DETTOL-HANDWASH-200', 'Dettol Handwash 200 ml', 'Soap & Shampoo', 'Dettol', 'Bottle', 380, '18', $box12],
            ['SUNSILK-SACHET', 'Sunsilk Shampoo sachet', 'Soap & Shampoo', 'Sunsilk', 'Sachet', 20, '18', $sachets],
            ['CLEAR-SACHET', 'Clear Shampoo sachet', 'Soap & Shampoo', 'Clear', 'Sachet', 20, '18', $sachets],
            ['HNS-SACHET', 'Head & Shoulders Shampoo sachet', 'Soap & Shampoo', 'Head & Shoulders', 'Sachet', 20, '18', $sachets],
            ['SUNSILK-180', 'Sunsilk Black Shine Shampoo 180 ml', 'Soap & Shampoo', 'Sunsilk', 'Bottle', 620, '18', $box12],
            ['HNS-185', 'Head & Shoulders Shampoo 185 ml', 'Soap & Shampoo', 'Head & Shoulders', 'Bottle', 850, '18', $box12],
            ['PANTENE-185', 'Pantene Shampoo 185 ml', 'Soap & Shampoo', 'Pantene', 'Bottle', 850, '18', $box12],

            // Other personal care
            ['PARACHUTE-175', 'Parachute Coconut Oil 175 ml', 'Personal Care', 'Parachute', 'Bottle', 450, '18', $box12],
            ['GLOWLOVELY-50', 'Glow & Lovely Cream 50 g', 'Personal Care', 'Glow & Lovely', 'Piece', 330, '18', $box12],
            ['VASELINE-200', 'Vaseline Lotion 200 ml', 'Personal Care', 'Vaseline', 'Bottle', 650, '18', $box12],
            ['TREET-BLADE-5', 'Treet Razor Blades, pack of 5', 'Personal Care', 'Treet', 'Packet', 60, '18', [['Box', 20]]],
            ['GILLETTE-BLUE2', 'Gillette Blue II razor', 'Personal Care', 'Gillette', 'Piece', 120, '18', $box24],
            ['ALWAYS-8', 'Always Ultra Thin, 8 pads', 'Personal Care', 'Always', 'Packet', 380, '18', $box12],

            // Oral care
            ['COLGATE-100', 'Colgate Toothpaste 100 g', 'Oral Care', 'Colgate', 'Piece', 340, '18', $box12],
            ['COLGATE-50', 'Colgate Toothpaste 50 g', 'Oral Care', 'Colgate', 'Piece', 180, '18', $box12],
            ['CLOSEUP-100', 'Close Up Toothpaste 100 g', 'Oral Care', 'Close Up', 'Piece', 320, '18', $box12],
            ['SENSODYNE-70', 'Sensodyne Toothpaste 70 g', 'Oral Care', 'Sensodyne', 'Piece', 650, '18', $box12],
            ['MEDICAM-70', 'Medicam Toothpaste 70 g', 'Oral Care', 'Medicam', 'Piece', 200, '18', $box12],
            ['COLGATE-BRUSH', 'Colgate Zig Zag toothbrush', 'Oral Care', 'Colgate', 'Piece', 180, '18', $box12],

            // Detergents and dishwashing
            ['SURF-500', 'Surf Excel 500 g', 'Detergents', 'Surf Excel', 'Packet', 400, '18', [['Carton', 18]]],
            ['SURF-1KG', 'Surf Excel 1 kg', 'Detergents', 'Surf Excel', 'Packet', 780, '18', [['Carton', 9]]],
            ['ARIEL-SACHET', 'Ariel washing powder sachet 35 g', 'Detergents', 'Ariel', 'Sachet', 30, '18', [['Packet', 20], ['Carton', 12]]],
            ['ARIEL-500', 'Ariel 500 g', 'Detergents', 'Ariel', 'Packet', 440, '18', [['Carton', 18]]],
            ['ARIEL-1KG', 'Ariel 1 kg', 'Detergents', 'Ariel', 'Packet', 850, '18', [['Carton', 9]]],
            ['BONUS-1KG', 'Bonus Tristar 1 kg', 'Detergents', 'Bonus', 'Packet', 450, '18', [['Carton', 9]]],
            ['BRITE-1KG', 'Brite Maximum Power 1 kg', 'Detergents', 'Brite', 'Packet', 620, '18', [['Carton', 9]]],
            ['COMFORT-400', 'Comfort Fabric Conditioner 400 ml', 'Detergents', 'Comfort', 'Bottle', 420, '18', $box12],
            ['LEMONMAX-BAR', 'Lemon Max dishwash bar', 'Detergents', 'Lemon Max', 'Piece', 60, '18', $box24],
            ['LEMONMAX-475', 'Lemon Max dishwash liquid 475 ml', 'Detergents', 'Lemon Max', 'Bottle', 430, '18', $box12],
            ['VIM-BAR', 'Vim dishwash bar', 'Detergents', 'Vim', 'Piece', 55, '18', $box24],

            // Cleaners, fresheners and the rest of the house
            ['HARPIC-500', 'Harpic Toilet Cleaner 500 ml', 'Cleaners & Fresheners', 'Harpic', 'Bottle', 480, '18', $box12],
            ['DETTOL-LIQUID-250', 'Dettol Antiseptic Liquid 250 ml', 'Cleaners & Fresheners', 'Dettol', 'Bottle', 520, '18', $box12],
            ['PHENYL-1L', 'White Phenyl 1 litre', 'Cleaners & Fresheners', null, 'Bottle', 250, '18', $box12],
            ['COLIN-500', 'Colin Glass Cleaner 500 ml', 'Cleaners & Fresheners', 'Colin', 'Bottle', 480, '18', $box12],
            ['MORTEIN-COIL', 'Mortein mosquito coils, pack of 10', 'Cleaners & Fresheners', 'Mortein', 'Packet', 180, '18', $box12],
            ['MORTEIN-375', 'Mortein Insect Killer spray 375 ml', 'Cleaners & Fresheners', 'Mortein', 'Can', 790, '18', $box12],
            ['AIRWICK-300', 'Air Wick Air Freshener 300 ml', 'Cleaners & Fresheners', 'Air Wick', 'Can', 650, '18', $box12],
            ['SCOTCHBRITE-PAD', 'Scotch-Brite scrub pad', 'Cleaners & Fresheners', 'Scotch-Brite', 'Piece', 120, '18', $box12],
            ['GARBAGE-BAGS-30', 'Garbage bags, roll of 30', 'Home Care & Cleaning', null, 'Packet', 250, '18', $box12],
            ['ROSEPETAL-TISSUE', 'Rose Petal facial tissue box', 'Home Care & Cleaning', 'Rose Petal', 'Box', 280, '18', $ctn24],

            // Baby care
            ['PAMPERS-M10', 'Pampers Medium, pack of 10', 'Baby Care', 'Pampers', 'Packet', 950, '18', [['Box', 6]]],
            ['PAMPERS-L8', 'Pampers Large, pack of 8', 'Baby Care', 'Pampers', 'Packet', 950, '18', [['Box', 6]]],
            ['MOLFIX-M18', 'Molfix Medium, pack of 18', 'Baby Care', 'Molfix', 'Packet', 1500, '18', [['Box', 4]]],
            ['PAMPERS-WIPES-64', 'Pampers Baby Wipes, 64', 'Baby Care', 'Pampers', 'Packet', 450, '18', $box12],
            ['JOHNSONS-SHAMPOO-200', 'Johnson\'s Baby Shampoo 200 ml', 'Baby Care', 'Johnson\'s', 'Bottle', 750, '18', $box12],
            ['JOHNSONS-POWDER-100', 'Johnson\'s Baby Powder 100 g', 'Baby Care', 'Johnson\'s', 'Bottle', 350, '18', $box12],
            ['CERELAC-175', 'Nestle Cerelac Wheat 175 g', 'Baby Care', 'Nestle', 'Packet', 700, '18', $ctn24],
            ['LACTOGEN1-400', 'Nestle Lactogen 1, 400 g', 'Baby Care', 'Nestle', 'Packet', 2900, '18', $ctn12],

            // Stationery
            ['COPY-80', 'Exercise book, 80 pages', 'Stationery', null, 'Piece', 90, '0', [['Packet', 10]]],
            ['COPY-200', 'Exercise book, 200 pages', 'Stationery', null, 'Piece', 220, '0', [['Packet', 10]]],
            ['PEN-BLUE', 'Piano ballpoint pen, blue', 'Stationery', 'Piano', 'Piece', 25, '18', [['Box', 50]]],
            ['POINTER-BLACK', 'Dollar Pointer, black', 'Stationery', 'Dollar', 'Piece', 50, '18', [['Box', 10]]],
            ['MARKER-PERM', 'Dollar Permanent Marker', 'Stationery', 'Dollar', 'Piece', 100, '18', [['Box', 10]]],
            ['PENCIL-HB', 'Pencil HB', 'Stationery', null, 'Piece', 20, '18', $box12],
            ['ERASER', 'Eraser', 'Stationery', null, 'Piece', 15, '18', $box30],
            ['SHARPENER', 'Sharpener', 'Stationery', null, 'Piece', 20, '18', $box24],
            ['UHU-STICK-8', 'UHU Glue Stick 8 g', 'Stationery', 'UHU', 'Piece', 120, '18', $box12],
            ['SCALE-12', 'Plastic scale, 12 inch', 'Stationery', null, 'Piece', 40, '18', $box12],
            ['A4-REAM', 'A4 paper, ream of 500', 'Stationery', null, 'Packet', 1800, '18', [['Box', 5]]],

            // Cigarettes and matches
            ['GOLDFLAKE-20', 'Gold Flake cigarettes', 'Cigarettes & Matches', 'Gold Flake', 'Piece', 25, '18', $cigarettes],
            ['CAPSTAN-20', 'Capstan cigarettes', 'Cigarettes & Matches', 'Capstan', 'Piece', 20, '18', $cigarettes],
            ['MORVEN-20', 'Morven Gold cigarettes', 'Cigarettes & Matches', 'Morven', 'Piece', 20, '18', $cigarettes],
            ['DUNHILL-20', 'Dunhill cigarettes', 'Cigarettes & Matches', 'Dunhill', 'Piece', 30, '18', $cigarettes],
            ['MARLBORO-20', 'Marlboro cigarettes', 'Cigarettes & Matches', 'Marlboro', 'Piece', 35, '18', $cigarettes],
            ['MATCHBOX', 'Match box', 'Cigarettes & Matches', null, 'Piece', 15, '0', [['Packet', 10], ['Carton', 60]]],
            ['LIGHTER-GAS', 'Gas lighter', 'Cigarettes & Matches', null, 'Piece', 50, '18', [['Box', 50]]],

            // Everything else in the kitchen cupboard
            ['KOLSON-SPAGHETTI-500', 'Kolson Spaghetti 500 g', 'Grocery & Staples', 'Kolson', 'Packet', 320, '18', [['Carton', 20]]],
            ['KOLSON-MACARONI-400', 'Kolson Macaroni 400 g', 'Grocery & Staples', 'Kolson', 'Packet', 220, '18', [['Carton', 20]]],
            ['KOLSON-VERMICELLI-150', 'Kolson Vermicelli 150 g', 'Grocery & Staples', 'Kolson', 'Packet', 100, '18', $box12],
            ['KNORR-NOODLES-66', 'Knorr Chicken Noodles 66 g', 'Grocery & Staples', 'Knorr', 'Packet', 80, '18', $boxCarton],
            ['MAGGI-NOODLES-62', 'Maggi Chicken Noodles 62 g', 'Grocery & Staples', 'Maggi', 'Packet', 75, '18', $boxCarton],
            ['KNORR-CUBE', 'Knorr Chicken Stock Cube', 'Grocery & Staples', 'Knorr', 'Piece', 25, '18', $box24],
            ['RAFHAN-CUSTARD-300', 'Rafhan Custard 300 g', 'Grocery & Staples', 'Rafhan', 'Packet', 300, '18', $ctn24],
            ['RAFHAN-JELLY-80', 'Rafhan Jelly Strawberry 80 g', 'Grocery & Staples', 'Rafhan', 'Packet', 110, '18', $box24],
            ['RAFHAN-KHEER-155', 'Rafhan Kheer Mix 155 g', 'Grocery & Staples', 'Rafhan', 'Packet', 180, '18', $box24],
            ['RAFHAN-CORNFLOUR-300', 'Rafhan Corn Flour 300 g', 'Grocery & Staples', 'Rafhan', 'Packet', 200, '18', $ctn24],
            ['NATIONAL-KETCHUP-800', 'National Tomato Ketchup 800 g', 'Grocery & Staples', 'National', 'Bottle', 600, '18', $ctn12],
            ['KNORR-KETCHUP-800', 'Knorr Tomato Ketchup 800 g', 'Grocery & Staples', 'Knorr', 'Bottle', 580, '18', $ctn12],
            ['YOUNGS-MAYO-1L', 'Young\'s Mayonnaise 1 litre', 'Grocery & Staples', 'Young\'s', 'Bottle', 900, '18', $ctn12],
            ['NATIONAL-VINEGAR-800', 'National Vinegar 800 ml', 'Grocery & Staples', 'National', 'Bottle', 180, '18', $ctn12],
            ['MITCHELLS-JAM-440', 'Mitchell\'s Mixed Fruit Jam 440 g', 'Grocery & Staples', 'Mitchell\'s', 'Bottle', 560, '18', $ctn12],
            ['AHMED-PICKLE-1KG', 'Ahmed Mixed Pickle 1 kg', 'Grocery & Staples', 'Ahmed', 'Bottle', 750, '18', $ctn6],
            ['LANGNESE-HONEY-250', 'Langnese Honey 250 g', 'Grocery & Staples', 'Langnese', 'Bottle', 950, '18', $ctn12],
            ['KOKO-KRUNCH-150', 'Nestle Koko Krunch 150 g', 'Grocery & Staples', 'Nestle', 'Box', 550, '18', $ctn12],
            ['CORNFLAKES-250', 'Kellogg\'s Corn Flakes 250 g', 'Grocery & Staples', 'Kellogg\'s', 'Box', 700, '18', $ctn12],
            ['FAUJI-OATS-500', 'Fauji Oats 500 g', 'Grocery & Staples', 'Fauji', 'Packet', 550, '18', $ctn12],
            ['KHAJOOR-LOOSE', 'Khajoor (loose)', 'Grocery & Staples', null, 'Gram', 900, '0', $kg],
            ['BADAM-LOOSE', 'Badam (loose)', 'Grocery & Staples', null, 'Gram', 3200, '0', $kg],
            ['KISHMISH-LOOSE', 'Kishmish (loose)', 'Grocery & Staples', null, 'Gram', 1400, '0', $kg],
        ]);
    }
}
