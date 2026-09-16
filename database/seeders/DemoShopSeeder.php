<?php

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Register;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\KhataService;
use App\Services\PackagingService;
use App\Services\StockService;
use App\Support\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * The shop itself: shelves, staff, suppliers and regulars.
 *
 * This is a made-up kiryana store on a main road in Karachi, stocked the way
 * a real one is — sachets and cartons of the same washing powder, loose atta
 * and rice by the kilo, cold drinks by the crate. It exists so that somebody
 * seeing the system for the first time can press every button and watch real
 * figures move, instead of staring at empty screens.
 *
 * Nothing here is trading history; DemoHistorySeeder does the selling. This
 * seeder only sets the shop up and puts the opening stock on the shelf.
 */
class DemoShopSeeder extends Seeder
{
    public function __construct(
        private readonly PackagingService $packaging,
        private readonly StockService $stock,
        private readonly KhataService $khata,
    ) {}

    /** The next barcode to print, so every packet gets its own. */
    private int $nextBarcode = 1;

    public function run(): void
    {
        $owner = User::firstWhere('role', Role::Owner) ?? User::firstWhere('email', 'owner@supermart.test');

        $this->seedStaff();
        $this->seedCounters();
        $this->seedSuppliers();
        $this->seedProducts($owner);
        $this->seedCustomers();

        $this->command?->info('The demo shop is stocked: '.Product::count().' products on the shelf.');
    }

    /**
     * A manager who can sign off a short drawer, and two cashiers who cannot.
     * Everyone signs in with the same password so the demo is easy to show.
     */
    private function seedStaff(): void
    {
        /** @var array<int, array{0: string, 1: string, 2: Role, 3: string, 4: string}> $staff */
        $staff = [
            ['Imran Shafiq', 'manager@supermart.test', Role::Manager, '0301-5512340', '2244'],
            ['Kashif Ali', 'kashif@supermart.test', Role::Cashier, '0333-5512341', '1122'],
            ['Nadia Bashir', 'nadia@supermart.test', Role::Cashier, '0345-5512342', '3344'],
        ];

        foreach ($staff as [$name, $email, $role, $phone, $pin]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'role' => $role,
                'phone' => $phone,
                'pin_code' => $pin,
                'password' => 'password',
                'is_active' => true,
            ]);
        }
    }

    /**
     * Two tills: the main counter by the door and a second one opened when
     * the evening rush builds up.
     */
    private function seedCounters(): void
    {
        Register::firstOrCreate(['name' => 'Counter 1'], ['location' => 'By the main door', 'is_active' => true]);
        Register::firstOrCreate(['name' => 'Counter 2'], ['location' => 'Back of the shop', 'is_active' => true]);
    }

    private function seedSuppliers(): void
    {
        /** @var array<int, array{0: string, 1: string, 2: string, 3: int}> $suppliers */
        $suppliers = [
            ['Khursheed Traders', 'Khursheed & Sons Distributors', '021-3455 1200', 15],
            ['Al-Madina Distributors', 'Al-Madina Trading Co.', '021-3455 1311', 30],
            ['Shahzad Cash & Carry', 'Shahzad Wholesale Market', '021-3455 1422', 0],
            ['Fresh Dairy Supply', 'Fresh Dairy Supply (Pvt) Ltd', '021-3455 1533', 7],
            ['Lucky Wholesale', 'Lucky Wholesale House', '021-3455 1644', 21],
        ];

        foreach ($suppliers as [$name, $company, $phone, $terms]) {
            Supplier::firstOrCreate(['name' => $name], [
                'company' => $company,
                'phone' => PhoneNumber::normalise($phone),
                'payment_terms_days' => $terms,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Regulars with a khata. A few of them were already owing money when the
     * shop started using the system, which is what the opening balance is for.
     */
    private function seedCustomers(): void
    {
        /** @var array<int, array{0: string, 1: string, 2: int, 3: int}> $customers */
        $customers = [
            ['Bilal Ahmed', '0300-5510001', 15_000_00, 0],
            ['Sana Yousuf', '0321-5510002', 10_000_00, 2_400_00],
            ['Haji Rafiq', '0333-5510003', 40_000_00, 12_500_00],
            ['Farhan Qureshi', '0345-5510004', 8_000_00, 0],
            ['Ayesha Siddiqui', '0301-5510005', 6_000_00, 850_00],
            ['Mehmood Bhai Tandoor', '0312-5510006', 30_000_00, 6_200_00],
            ['Zubair Electric Store', '0302-5510007', 25_000_00, 0],
            ['Rehana Bibi', '0344-5510008', 5_000_00, 1_150_00],
            ['Kamran Sheikh', '0323-5510009', 12_000_00, 0],
            ['Noor Muhammad', '0335-5510010', 7_500_00, 300_00],
            ['Shahid Karyana Helper', '0311-5510011', 4_000_00, 0],
            ['Uzma Parveen', '0308-5510012', 6_500_00, 0],
            ['Iqbal Chai Hotel', '0304-5510013', 35_000_00, 9_800_00],
            ['Saleem Painter', '0315-5510014', 5_000_00, 0],
        ];

        foreach ($customers as [$name, $phone, $limit, $opening]) {
            $customer = Customer::firstOrCreate(['name' => $name], [
                'phone' => PhoneNumber::normalise($phone),
                'credit_limit_paisa' => $limit,
                'is_active' => true,
            ]);

            if ($opening > 0 && $customer->wasRecentlyCreated) {
                $this->khata->opening($customer, $opening, 'Brought forward from the old register');
            }
        }
    }

    private function seedProducts(?User $owner): void
    {
        $units = Unit::pluck('id', 'name');
        $categories = Category::pluck('id', 'name');

        foreach ($this->shelf() as $row) {
            $product = Product::firstOrCreate(['sku' => $row['sku']], [
                'name' => $row['name'],
                'category_id' => $categories[$row['category']] ?? null,
                'brand_id' => $row['brand'] === null ? null : $this->brandId($row['brand']),
                'base_unit_id' => $units[$row['base']],
                'tax_rate' => $row['tax'],
                'is_weighted' => $row['base'] === 'Gram',
                'reorder_level_base' => intdiv($row['stock'], 4),
                'reorder_qty_base' => $row['stock'],
                'is_active' => true,
            ]);

            if (! $product->wasRecentlyCreated) {
                continue;
            }

            $this->packaging->sync($product, $this->levels($row, $units));

            $this->stock->record(
                product: $product,
                qtyBase: $row['stock'],
                type: MovementType::Opening,
                unitCostPaisa: $row['cost'],
                userId: $owner?->getKey(),
                note: 'Counted when the system was set up',
            );
        }
    }

    /**
     * Turn one shelf row into the packaging levels PackagingService wants.
     *
     * The rows describe packing the way a shopkeeper says it — "a box holds
     * 12, a carton holds 6 boxes" — so each level names the smaller unit it
     * is packed from, and the service works the rest out.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, int>  $units
     * @return array<int, array<string, mixed>>
     */
    private function levels(array $row, Collection $units): array
    {
        $levels = [[
            'unit_id' => $units[$row['base']],
            'parent_unit_id' => null,
            'qty_per_parent' => 1,
            'sale_price_paisa' => $row['price'],
            'mrp_paisa' => null,
            'is_default_sale' => $row['packs'] === [] || $row['base'] !== 'Gram',
            'is_default_purchase' => $row['packs'] === [],
            'barcodes' => [$this->barcode()],
        ]];

        $parent = $row['base'];
        $last = count($row['packs']) - 1;

        foreach ($row['packs'] as $index => [$unitName, $perParent, $price]) {
            $levels[] = [
                'unit_id' => $units[$unitName],
                'parent_unit_id' => $units[$parent],
                'qty_per_parent' => $perParent,
                'sale_price_paisa' => $price,
                'mrp_paisa' => null,
                /* Loose goods are rung up by the kilo, never by the gram. */
                'is_default_sale' => $row['base'] === 'Gram' && $index === 0,
                'is_default_purchase' => $index === $last,
                'barcodes' => [$this->barcode()],
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
     * A printable barcode, made up but properly formed: the 896 country
     * prefix Pakistan uses, and a real check digit, so a scanner reading one
     * off the screen behaves exactly as it would in the shop.
     */
    private function barcode(): string
    {
        $body = '896'.str_pad((string) $this->nextBarcode++, 9, '0', STR_PAD_LEFT);
        $sum = 0;

        foreach (str_split($body) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return $body.((10 - $sum % 10) % 10);
    }

    /**
     * What is on the shelf.
     *
     * `base` is the smallest piece that can be sold; `packs` are the bigger
     * sizes it comes packed in, each one counted in the size below it.
     * `price` and `cost` are for one base unit, in paisa, and `stock` is how
     * many base units were on the shelf on the first day.
     *
     * Loose goods are based on the gram so that half a kilo of rice is a
     * whole number of base units; they are still rung up by the kilo.
     *
     * @return array<int, array{sku: string, name: string, category: string, brand: string|null, base: string, price: int, cost: int, stock: int, tax: string, packs: array<int, array{0: string, 1: int, 2: int}>}>
     */
    private function shelf(): array
    {
        return [
            $this->loose('ATTA-CHAKKI', 'Chakki Atta (loose)', 'Flour & Rice', null, 140_00, 120_00, 300, '0'),
            $this->loose('RICE-SUPER', 'Super Kernel Basmati Rice (loose)', 'Flour & Rice', null, 420_00, 360_00, 180, '0'),
            $this->loose('DAL-CHANA', 'Chana Daal (loose)', 'Pulses & Beans', null, 330_00, 290_00, 120, '0'),
            $this->loose('DAL-MASOOR', 'Masoor Daal (loose)', 'Pulses & Beans', null, 380_00, 330_00, 90, '0'),
            $this->loose('SUGAR-LOOSE', 'Sugar (loose)', 'Sugar & Salt', null, 190_00, 170_00, 250, '0'),

            $this->packed('SUNRIDGE-5KG', 'Sunridge Fine Atta 5 kg bag', 'Flour & Rice', 'Sunridge', 'Bag', 890_00, 820_00, 40, '0', [['Carton', 6, 5_280_00]]),
            $this->packed('DALDA-OIL-1L', 'Dalda Cooking Oil 1 litre', 'Cooking Oil & Ghee', 'Dalda', 'Bottle', 620_00, 560_00, 96, '18', [['Carton', 12, 7_200_00]]),
            $this->packed('DALDA-GHEE-1K', 'Dalda Banaspati Ghee 1 kg', 'Cooking Oil & Ghee', 'Dalda', 'Packet', 640_00, 580_00, 72, '18', [['Carton', 12, 7_440_00]]),
            $this->packed('SUFI-OIL-5L', 'Sufi Cooking Oil 5 litre', 'Cooking Oil & Ghee', 'Sufi', 'Bottle', 3_050_00, 2_820_00, 24, '18', [['Carton', 4, 11_900_00]]),

            $this->packed('LIPTON-190', 'Lipton Yellow Label 190 g', 'Tea & Coffee', 'Lipton', 'Packet', 780_00, 705_00, 60, '18', [['Carton', 24, 18_000_00]]),
            $this->packed('TAPAL-900', 'Tapal Danedar 900 g', 'Tea & Coffee', 'Tapal', 'Packet', 2_450_00, 2_260_00, 24, '18', [['Carton', 6, 14_400_00]]),
            $this->packed('NESCAFE-50', 'Nescafe Classic 50 g', 'Tea & Coffee', 'Nescafe', 'Bottle', 1_250_00, 1_140_00, 18, '18', [['Box', 6, 7_320_00]]),

            /* Three levels deep: a sachet, a dozen sachets to a box, six
               boxes to a carton. The till has to convert both jumps. */
            $this->packed('SHAN-BIRYANI', 'Shan Bombay Biryani Masala 60 g', 'Spices & Masala', 'Shan', 'Sachet', 160_00, 132_00, 288, '18', [['Box', 12, 1_860_00], ['Carton', 6, 10_900_00]]),
            $this->packed('NATIONAL-CHAAT', 'National Chaat Masala 100 g', 'Spices & Masala', 'National', 'Sachet', 195_00, 168_00, 144, '18', [['Box', 12, 2_280_00]]),

            $this->packed('COKE-1500', 'Coca-Cola 1.5 litre', 'Soft Drinks', 'Coca-Cola', 'Bottle', 220_00, 190_00, 120, '18', [['Carton', 6, 1_260_00]]),
            $this->packed('PEPSI-345', 'Pepsi 345 ml can', 'Soft Drinks', 'Pepsi', 'Can', 110_00, 92_00, 144, '18', [['Packet', 6, 640_00], ['Carton', 4, 2_480_00]]),
            $this->packed('NESTLE-1500', 'Nestle Pure Life 1.5 litre', 'Juices & Water', 'Nestle', 'Bottle', 140_00, 118_00, 90, '18', [['Packet', 6, 800_00]]),
            $this->packed('SLICE-200', 'Slice Mango Juice 200 ml', 'Juices & Water', 'Slice', 'Packet', 70_00, 58_00, 240, '18', [['Box', 24, 1_600_00]]),

            $this->packed('SOOPER-FAMILY', 'Sooper Biscuit family pack', 'Biscuits', 'EBM', 'Packet', 150_00, 126_00, 288, '18', [['Box', 12, 1_740_00], ['Carton', 6, 10_200_00]]),
            $this->packed('GALA-TICKY', 'Gala Biscuit ticky pack', 'Biscuits', 'LU', 'Packet', 30_00, 24_00, 576, '18', [['Box', 24, 690_00], ['Carton', 12, 8_100_00]]),
            $this->packed('PRINCE-20', 'Prince Biscuit Rs 20 pack', 'Biscuits', 'LU', 'Packet', 20_00, 16_00, 480, '18', [['Box', 24, 460_00]]),
            $this->packed('LAYS-MASALA', 'Lays Masala 40 g', 'Chips & Namkeen', 'Lays', 'Packet', 90_00, 74_00, 240, '18', [['Box', 24, 2_100_00]]),
            $this->packed('KURKURE-30', 'Kurkure Chatpata 30 g', 'Chips & Namkeen', 'Kurkure', 'Packet', 50_00, 41_00, 300, '18', [['Box', 30, 1_440_00]]),
            $this->packed('DAIRYMILK-35', 'Cadbury Dairy Milk 35 g', 'Chocolates & Sweets', 'Cadbury', 'Piece', 160_00, 134_00, 144, '18', [['Box', 24, 3_720_00]]),
            $this->packed('CHILLI-MILLI', 'Chilli Milli jelly', 'Chocolates & Sweets', 'Hilal', 'Piece', 20_00, 16_00, 400, '18', [['Box', 50, 920_00]]),

            $this->packed('OLPERS-1L', 'Olpers Milk 1 litre', 'Dairy & Eggs', 'Olpers', 'Packet', 320_00, 292_00, 120, '18', [['Carton', 12, 3_780_00]]),
            $this->packed('EGGS', 'Farm Eggs', 'Dairy & Eggs', null, 'Piece', 35_00, 29_00, 300, '0', [['Tray', 30, 1_000_00]]),
            $this->packed('NURPUR-BUTTER', 'Nurpur Butter 200 g', 'Dairy & Eggs', 'Nurpur', 'Packet', 640_00, 590_00, 36, '18', [['Box', 12, 7_560_00]]),
            $this->packed('DAWN-BREAD', 'Dawn Bread large', 'Bakery', 'Dawn', 'Packet', 210_00, 180_00, 30, '0', []),
            $this->packed('RUSK-MILK', 'Peek Freans Milk Rusk', 'Bakery', 'Peek Freans', 'Packet', 330_00, 300_00, 40, '18', [['Box', 8, 2_560_00]]),

            $this->packed('SAFEGUARD-130', 'Safeguard Soap 130 g', 'Soap & Shampoo', 'Safeguard', 'Piece', 230_00, 196_00, 144, '18', [['Box', 12, 2_640_00], ['Carton', 6, 15_500_00]]),
            /* The sachet chain every kiryana runs on: one rupee sachet, a
               strip of sixteen, a carton of two dozen strips. */
            $this->packed('SUNSILK-SACHET', 'Sunsilk Shampoo sachet', 'Soap & Shampoo', 'Sunsilk', 'Sachet', 20_00, 15_00, 768, '18', [['Packet', 16, 300_00], ['Carton', 24, 7_000_00]]),
            $this->packed('COLGATE-100', 'Colgate Toothpaste 100 g', 'Oral Care', 'Colgate', 'Piece', 340_00, 300_00, 72, '18', [['Box', 12, 3_960_00]]),
            $this->packed('SURF-1KG', 'Surf Excel 1 kg', 'Detergents', 'Surf Excel', 'Packet', 780_00, 715_00, 54, '18', [['Carton', 9, 6_800_00]]),
            $this->packed('ARIEL-SACHET', 'Ariel washing powder sachet 35 g', 'Detergents', 'Ariel', 'Sachet', 30_00, 24_00, 960, '18', [['Packet', 20, 560_00], ['Carton', 12, 6_600_00]]),
            $this->packed('HARPIC-500', 'Harpic Toilet Cleaner 500 ml', 'Cleaners & Fresheners', 'Harpic', 'Bottle', 480_00, 430_00, 36, '18', [['Box', 12, 5_640_00]]),
            $this->packed('PAMPERS-M10', 'Pampers Medium, pack of 10', 'Baby Care', 'Pampers', 'Packet', 950_00, 880_00, 24, '18', [['Box', 6, 5_520_00]]),
            $this->packed('COPY-80', 'Exercise book, 80 pages', 'Stationery', null, 'Piece', 90_00, 72_00, 100, '0', [['Packet', 10, 850_00]]),
            $this->packed('PEN-BLUE', 'Piano ballpoint pen, blue', 'Stationery', 'Piano', 'Piece', 25_00, 18_00, 200, '18', [['Box', 50, 1_150_00]]),
            $this->packed('MATCHBOX', 'Match box', 'Cigarettes & Matches', null, 'Piece', 15_00, 11_00, 600, '0', [['Packet', 10, 140_00], ['Carton', 60, 8_100_00]]),
        ];
    }

    /**
     * A loose item, weighed out on the scale and sold by the kilo.
     *
     * @return array<string, mixed>
     */
    private function loose(string $sku, string $name, string $category, ?string $brand, int $perKiloPaisa, int $costPerKiloPaisa, int $kilos, string $tax): array
    {
        return [
            'sku' => $sku,
            'name' => $name,
            'category' => $category,
            'brand' => $brand,
            'base' => 'Gram',
            'price' => intdiv($perKiloPaisa, 1000),
            'cost' => intdiv($costPerKiloPaisa, 1000),
            'stock' => $kilos * 1000,
            'tax' => $tax,
            'packs' => [['Kilogram', 1000, $perKiloPaisa]],
        ];
    }

    /**
     * An item that comes ready packed.
     *
     * @param  array<int, array{0: string, 1: int, 2: int}>  $packs
     * @return array<string, mixed>
     */
    private function packed(string $sku, string $name, string $category, ?string $brand, string $base, int $price, int $cost, int $stock, string $tax, array $packs): array
    {
        return [
            'sku' => $sku,
            'name' => $name,
            'category' => $category,
            'brand' => $brand,
            'base' => $base,
            'price' => $price,
            'cost' => $cost,
            'stock' => $stock,
            'tax' => $tax,
            'packs' => $packs,
        ];
    }
}
