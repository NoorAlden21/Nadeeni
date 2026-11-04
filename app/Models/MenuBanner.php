<?php

namespace App\Models;

use App\Support\ApiAssetUrl;
use App\Support\StoredImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

class MenuBanner extends Model
{
    public const LIMIT = 5;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'restaurant_id',
        'sort_order',
        'is_active',
        'image',
        'image_mime',
        'image_sha',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'image',
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

    /**
     * @param  Builder<MenuBanner>  $query
     * @return Builder<MenuBanner>
     */
    public function scopeWithoutImage(Builder $query): Builder
    {
        return $query->select([
            'id',
            'restaurant_id',
            'sort_order',
            'is_active',
            'image_sha',
            'created_at',
            'updated_at',
        ]);
    }

    public function storeImage(UploadedFile $file): void
    {
        [$encoded, $mime] = StoredImage::encode($file);

        $this->forceFill([
            'image' => $encoded,
            'image_mime' => $mime,
            'image_sha' => substr(sha1($encoded), 0, 12),
        ])->save();
    }

    public function imageBytes(): ?string
    {
        return StoredImage::decode($this->image);
    }

    public function imageUrl(): ?string
    {
        if ($this->image_sha === null) {
            return null;
        }

        $this->loadMissing('restaurant');

        return ApiAssetUrl::make(
            '/api/public/restaurants/'.$this->restaurant->slug.'/banners/'.$this->id.'/image?v='.$this->image_sha
        );
    }
}
