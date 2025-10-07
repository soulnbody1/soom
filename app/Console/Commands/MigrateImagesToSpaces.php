<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Category; // عدّل لأي موديل عندك

class MigrateImagesToSpaces extends Command
{
    protected $signature = 'migrate:images-spaces';
    protected $description = 'نقل الصور من التخزين المحلي إلى DigitalOcean Spaces وتحديث قاعدة البيانات وحذف الملفات القديمة';

    public function handle()
    {
        $this->info("بدء عملية نقل الصور إلى DigitalOcean Spaces...");

        // مثال على جدول Categories، كرر لكل جدول عندك
        $categories = Category::all();

        foreach ($categories as $category) {

            // اسم العمود اللي فيه الصورة
            $column = 'image'; 

            $localPath = $category->$column; // الاسم النسبي المخزن في DB

            if (!$localPath) {
                $this->warn("السجل ID {$category->id} لا يحتوي على صورة");
                continue;
            }

            // تحقق أن الصورة موجودة على التخزين المحلي
            if (!Storage::disk('public')->exists($localPath)) {
                $this->warn("ملف غير موجود على السيرفر: $localPath");
                continue;
            }

            try {
                // قراءة محتوى الملف
                $content = Storage::disk('public')->get($localPath);

                // رفع الملف على DigitalOcean Spaces
                Storage::disk('spaces')->put($localPath, $content);

                // تحديث المسار في قاعدة البيانات (الاسم النسبي يكفي)
                $category->update([$column => $localPath]);

                // حذف الملف من السيرفر المحلي بعد التأكد من رفعه
                Storage::disk('public')->delete($localPath);

                $this->info("تم نقل الملف بنجاح: $localPath");

            } catch (\Exception $e) {
                $this->error("حدث خطأ مع الملف $localPath: " . $e->getMessage());
            }
        }

        $this->info("تم الانتهاء من نقل كل الصور.");
    }
}
