<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;

final class Reference
{
    public static function assign(Model $model, string $prefix): string
    {
        $zone = Setting::valueFor('business_timezone', config('sfa.timezone', 'Asia/Manila'));
        $year = $prefix === 'TCK' ? '' : now()->timezone($zone)->format('Y').'-';
        $reference = $prefix.'-'.$year.str_pad((string) $model->getKey(), 6, '0', STR_PAD_LEFT);
        $model->forceFill(['reference' => $reference])->save();
        return $reference;
    }
}
