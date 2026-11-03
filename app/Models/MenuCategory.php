<?php

namespace App\Models;

use App\Support\ApiAssetUrl;
use App\Support\StoredImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;

class MenuCategory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'restaurant_id',
        'name_ar',
        'name_en',
        'sort_order',
        'is_active',
        'icon',
        'icon_mime',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'icon',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function storeIcon(UploadedFile $file): void
    {
        [$encoded, $mime] = StoredImage::encode($file);

        $this->forceFill([
            'icon' => $encoded,
            'icon_mime' => $mime,
        ])->save();
    }

    public function iconBytes(): ?string
    {
        return StoredImage::decode($this->icon);
    }

    public function iconUrl(): ?string
    {
        if ($this->icon === null) {
            return null;
        }

        $this->loadMissing('restaurant');

        return ApiAssetUrl::make(
            '/api/public/restaurants/'.$this->restaurant->slug.'/menu-categories/'.$this->id.'/icon?v='.substr(sha1((string) $this->icon), 0, 12)
        );
    }
}
