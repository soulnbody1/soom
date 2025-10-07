<?PHP
// app/Http/Resources/FavoriteResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'    => $this->id,
            'ad_id' => $this->ad_id,
            'ad'    => new AdResource($this->whenLoaded('ad')),
            'added_at' => $this->created_at->diffForHumans(),
        ];
    }
}
