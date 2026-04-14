<?php

namespace Database\Seeders;

use App\Models\Actor;
use App\Models\Country;
use App\Models\Genre;
use App\Models\Language;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedModel(Genre::class, [
            ['name' => 'اکشن', 'slug' => 'action', 'description' => 'فیلم‌های اکشن و پرتنش', 'sort_order' => 10],
            ['name' => 'ماجراجویی', 'slug' => 'adventure', 'description' => 'ماجراجویی و سفر', 'sort_order' => 20],
            ['name' => 'انیمیشن', 'slug' => 'animation', 'description' => 'آثار انیمیشنی', 'sort_order' => 30],
            ['name' => 'زندگینامه', 'slug' => 'biography', 'description' => 'روایت زندگی شخصیت‌ها', 'sort_order' => 40],
            ['name' => 'کمدی', 'slug' => 'comedy', 'description' => 'فیلم‌های طنز و کمدی', 'sort_order' => 50],
            ['name' => 'جنایی', 'slug' => 'crime', 'description' => 'جرم، معما و پلیسی', 'sort_order' => 60],
            ['name' => 'مستند', 'slug' => 'documentary', 'description' => 'آثار مستند', 'sort_order' => 70],
            ['name' => 'درام', 'slug' => 'drama', 'description' => 'فیلم‌های درام', 'sort_order' => 80],
            ['name' => 'خانوادگی', 'slug' => 'family', 'description' => 'مناسب تماشای خانوادگی', 'sort_order' => 90],
            ['name' => 'فانتزی', 'slug' => 'fantasy', 'description' => 'دنیای خیال و فانتزی', 'sort_order' => 100],
            ['name' => 'تاریخی', 'slug' => 'history', 'description' => 'آثار تاریخی', 'sort_order' => 110],
            ['name' => 'ترسناک', 'slug' => 'horror', 'description' => 'فیلم‌های ترسناک', 'sort_order' => 120],
            ['name' => 'معمایی', 'slug' => 'mystery', 'description' => 'رازآلود و معمایی', 'sort_order' => 130],
            ['name' => 'عاشقانه', 'slug' => 'romance', 'description' => 'عاشقانه و احساسی', 'sort_order' => 140],
            ['name' => 'علمی تخیلی', 'slug' => 'sci-fi', 'description' => 'آثار علمی تخیلی', 'sort_order' => 150],
            ['name' => 'هیجانی', 'slug' => 'thriller', 'description' => 'تعلیق و هیجان', 'sort_order' => 160],
            ['name' => 'جنگی', 'slug' => 'war', 'description' => 'آثار جنگی', 'sort_order' => 170],
            ['name' => 'وسترن', 'slug' => 'western', 'description' => 'وسترن و کابویی', 'sort_order' => 180],
        ]);

        $this->seedModel(Country::class, [
            ['name' => 'ایالات متحده', 'slug' => 'united-states', 'description' => 'ایالات متحده آمریکا', 'sort_order' => 10],
            ['name' => 'بریتانیا', 'slug' => 'united-kingdom', 'description' => 'بریتانیا', 'sort_order' => 20],
            ['name' => 'هند', 'slug' => 'india', 'description' => 'هند', 'sort_order' => 30],
            ['name' => 'فرانسه', 'slug' => 'france', 'description' => 'فرانسه', 'sort_order' => 40],
            ['name' => 'آلمان', 'slug' => 'germany', 'description' => 'آلمان', 'sort_order' => 50],
            ['name' => 'ایتالیا', 'slug' => 'italy', 'description' => 'ایتالیا', 'sort_order' => 60],
            ['name' => 'اسپانیا', 'slug' => 'spain', 'description' => 'اسپانیا', 'sort_order' => 70],
            ['name' => 'ترکیه', 'slug' => 'turkey', 'description' => 'ترکیه', 'sort_order' => 80],
            ['name' => 'ایران', 'slug' => 'iran', 'description' => 'ایران', 'sort_order' => 90],
            ['name' => 'افغانستان', 'slug' => 'afghanistan', 'description' => 'افغانستان', 'sort_order' => 100],
            ['name' => 'کره جنوبی', 'slug' => 'south-korea', 'description' => 'کره جنوبی', 'sort_order' => 110],
            ['name' => 'ژاپن', 'slug' => 'japan', 'description' => 'ژاپن', 'sort_order' => 120],
            ['name' => 'چین', 'slug' => 'china', 'description' => 'چین', 'sort_order' => 130],
            ['name' => 'کانادا', 'slug' => 'canada', 'description' => 'کانادا', 'sort_order' => 140],
            ['name' => 'روسیه', 'slug' => 'russia', 'description' => 'روسیه', 'sort_order' => 150],
        ]);

        $this->seedModel(Language::class, [
            ['name' => 'انگلیسی', 'slug' => 'english', 'description' => 'زبان انگلیسی', 'sort_order' => 10],
            ['name' => 'فارسی', 'slug' => 'persian', 'description' => 'فارسی', 'sort_order' => 20],
            ['name' => 'دری', 'slug' => 'dari', 'description' => 'دری', 'sort_order' => 30],
            ['name' => 'پشتو', 'slug' => 'pashto', 'description' => 'پشتو', 'sort_order' => 40],
            ['name' => 'عربی', 'slug' => 'arabic', 'description' => 'عربی', 'sort_order' => 50],
            ['name' => 'هندی', 'slug' => 'hindi', 'description' => 'هندی', 'sort_order' => 60],
            ['name' => 'ترکی', 'slug' => 'turkish', 'description' => 'ترکی استانبولی', 'sort_order' => 70],
            ['name' => 'فرانسوی', 'slug' => 'french', 'description' => 'فرانسوی', 'sort_order' => 80],
            ['name' => 'آلمانی', 'slug' => 'german', 'description' => 'آلمانی', 'sort_order' => 90],
            ['name' => 'اسپانیایی', 'slug' => 'spanish', 'description' => 'اسپانیایی', 'sort_order' => 100],
            ['name' => 'ایتالیایی', 'slug' => 'italian', 'description' => 'ایتالیایی', 'sort_order' => 110],
            ['name' => 'ژاپنی', 'slug' => 'japanese', 'description' => 'ژاپنی', 'sort_order' => 120],
            ['name' => 'کره‌ای', 'slug' => 'korean', 'description' => 'کره‌ای', 'sort_order' => 130],
            ['name' => 'چینی', 'slug' => 'chinese', 'description' => 'چینی', 'sort_order' => 140],
            ['name' => 'روسی', 'slug' => 'russian', 'description' => 'روسی', 'sort_order' => 150],
        ]);

        $this->seedModel(Tag::class, [
            ['name' => 'برتر IMDb', 'slug' => 'top-imdb', 'description' => 'امتیاز بالا در IMDb', 'sort_order' => 10],
            ['name' => 'برنده جایزه', 'slug' => 'award-winner', 'description' => 'برنده جایزه', 'sort_order' => 20],
            ['name' => 'جشنواره‌ای', 'slug' => 'festival', 'description' => 'منتخب جشنواره', 'sort_order' => 30],
            ['name' => 'دوبله', 'slug' => 'dubbed', 'description' => 'دارای دوبله', 'sort_order' => 40],
            ['name' => 'زیرنویس', 'slug' => 'subtitled', 'description' => 'دارای زیرنویس', 'sort_order' => 50],
            ['name' => 'کیفیت 1080p', 'slug' => '1080p', 'description' => 'کیفیت Full HD', 'sort_order' => 60],
            ['name' => '4K', 'slug' => '4k', 'description' => 'کیفیت 4K', 'sort_order' => 70],
            ['name' => 'نسخه کارگردان', 'slug' => 'directors-cut', 'description' => 'نسخه کارگردان', 'sort_order' => 80],
            ['name' => 'نامزد اسکار', 'slug' => 'oscar-nominee', 'description' => 'نامزد اسکار', 'sort_order' => 90],
            ['name' => 'ترند', 'slug' => 'trending', 'description' => 'ترند و پرمخاطب', 'sort_order' => 100],
            ['name' => 'کلاسیک', 'slug' => 'classic', 'description' => 'اثر کلاسیک', 'sort_order' => 110],
            ['name' => 'تازه منتشرشده', 'slug' => 'new-release', 'description' => 'تازه منتشرشده', 'sort_order' => 120],
        ]);

        $this->seedModel(Actor::class, [
            ['name' => 'تیموتی شالامی', 'slug' => 'timothee-chalamet', 'description' => 'بازیگر آمریکایی و فرانسوی', 'sort_order' => 10, 'imdb_url' => 'https://www.imdb.com/name/nm3154303/'],
            ['name' => 'زندیا', 'slug' => 'zendaya', 'description' => 'بازیگر و خواننده آمریکایی', 'sort_order' => 20, 'imdb_url' => 'https://www.imdb.com/name/nm3918035/'],
            ['name' => 'لئوناردو دی‌کاپریو', 'slug' => 'leonardo-dicaprio', 'description' => 'بازیگر آمریکایی', 'sort_order' => 30, 'imdb_url' => 'https://www.imdb.com/name/nm0000138/'],
            ['name' => 'تام کروز', 'slug' => 'tom-cruise', 'description' => 'بازیگر آمریکایی', 'sort_order' => 40, 'imdb_url' => 'https://www.imdb.com/name/nm0000129/'],
            ['name' => 'رابرت داونی جونیور', 'slug' => 'robert-downey-jr', 'description' => 'بازیگر آمریکایی', 'sort_order' => 50, 'imdb_url' => 'https://www.imdb.com/name/nm0000375/'],
            ['name' => 'کیلین مورفی', 'slug' => 'cillian-murphy', 'description' => 'بازیگر ایرلندی', 'sort_order' => 60, 'imdb_url' => 'https://www.imdb.com/name/nm0614165/'],
            ['name' => 'مارگو رابی', 'slug' => 'margot-robbie', 'description' => 'بازیگر استرالیایی', 'sort_order' => 70, 'imdb_url' => 'https://www.imdb.com/name/nm3053338/'],
            ['name' => 'اما استون', 'slug' => 'emma-stone', 'description' => 'بازیگر آمریکایی', 'sort_order' => 80, 'imdb_url' => 'https://www.imdb.com/name/nm1297015/'],
            ['name' => 'هنری کویل', 'slug' => 'henry-cavill', 'description' => 'بازیگر بریتانیایی', 'sort_order' => 90, 'imdb_url' => 'https://www.imdb.com/name/nm0147147/'],
            ['name' => 'برد پیت', 'slug' => 'brad-pitt', 'description' => 'بازیگر آمریکایی', 'sort_order' => 100, 'imdb_url' => 'https://www.imdb.com/name/nm0000093/'],
            ['name' => 'جانی دپ', 'slug' => 'johnny-depp', 'description' => 'بازیگر آمریکایی', 'sort_order' => 110, 'imdb_url' => 'https://www.imdb.com/name/nm0000136/'],
            ['name' => 'کریستین بیل', 'slug' => 'christian-bale', 'description' => 'بازیگر بریتانیایی', 'sort_order' => 120, 'imdb_url' => 'https://www.imdb.com/name/nm0000288/'],
            ['name' => 'اسکارلت جوهانسون', 'slug' => 'scarlett-johansson', 'description' => 'بازیگر آمریکایی', 'sort_order' => 130, 'imdb_url' => 'https://www.imdb.com/name/nm0424060/'],
            ['name' => 'آنا د آرماس', 'slug' => 'ana-de-armas', 'description' => 'بازیگر کوبایی', 'sort_order' => 140, 'imdb_url' => 'https://www.imdb.com/name/nm1869101/'],
            ['name' => 'کیانو ریوز', 'slug' => 'keanu-reeves', 'description' => 'بازیگر کانادایی', 'sort_order' => 150, 'imdb_url' => 'https://www.imdb.com/name/nm0000206/'],
        ]);
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @param array<int, array{name:string,slug:string,description:string,sort_order:int,imdb_url?:string}> $items
     */
    private function seedModel(string $modelClass, array $items): void
    {
        foreach ($items as $item) {
            $attributes = [
                'name' => $item['name'],
                'description' => $item['description'],
                'is_active' => true,
                'sort_order' => $item['sort_order'],
            ];

            if ($modelClass === Actor::class) {
                $attributes['imdb_url'] = $item['imdb_url'] ?? null;
            }

            $modelClass::query()->updateOrCreate(
                ['slug' => $item['slug']],
                $attributes
            );
        }
    }
}
