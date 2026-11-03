<?php

namespace App\Models;

use App\Support\ApiAssetUrl;
use App\Support\StoredImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

class MenuItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'restaurant_id',
        'menu_category_id',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'price',
        'sort_order',
        'is_available',
        'photo',
        'photo_mime',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'photo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sort_order' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function storePhoto(UploadedFile $file): void
    {
        [$encoded, $mime] = StoredImage::encode($file);

        $this->forceFill([
            'photo' => $encoded,
            'photo_mime' => $mime,
        ])->save();
    }

    public function photoBytes(): ?string
    {
        return StoredImage::decode($this->photo);
    }

    public function photoUrl(): ?string
    {
        if ($this->photo === null) {
            return null;
        }

        $this->loadMissing('restaurant');

        return ApiAssetUrl::make(
            '/api/public/restaurants/'.$this->restaurant->slug.'/menu-items/'.$this->id.'/photo?v='.substr(sha1((string) $this->photo), 0, 12)
        );
    }
}
