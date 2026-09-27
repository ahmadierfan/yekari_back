<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'title' => "$req|string|max:60", 'detail' => "$req|string|max:255", 'icon' => 'sometimes|string|max:40',
            'lat' => "$req|numeric|between:24,40", 'lng' => "$req|numeric|between:44,64",
            'x' => 'sometimes|integer|between:0,100', 'y' => 'sometimes|integer|between:0,100',
        ];
    }

    private function map(array $d): array
    {
        foreach (['x' => 'map_x', 'y' => 'map_y'] as $a => $b) {
            if (array_key_exists($a, $d)) {
                $d[$b] = $d[$a];
                unset($d[$a]);
            }
        }

        return $d;
    }

    public function index(Request $r)
    {
        return AddressResource::collection($r->user()->addresses);
    }

    public function store(Request $r)
    {
        $d = $this->map($r->validate($this->rules()));
        $sort = (int) $r->user()->addresses()->max('sort') + 1;

        return new AddressResource($r->user()->addresses()->create($d + ['sort' => $sort]));
    }

    public function update(Request $r, Address $address)
    {
        abort_unless($address->user_id === $r->user()->id, 404);
        $address->update($this->map($r->validate($this->rules(true))));

        return new AddressResource($address);
    }

    public function destroy(Request $r, Address $address)
    {
        abort_unless($address->user_id === $r->user()->id, 404);
        $address->delete();

        return response()->noContent();
    }

    /** پیش‌فرض = اول فهرست (همان قاعدهٔ اپ)، نه فلگ جدا */
    public function makeDefault(Request $r, Address $address)
    {
        abort_unless($address->user_id === $r->user()->id, 404);
        $address->update(['sort' => (int) $r->user()->addresses()->min('sort') - 1]);

        return AddressResource::collection($r->user()->addresses()->get());
    }
}
