<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\BusinessProfile;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Shop identity (name, logo, colour, contacts) used by emails and the storefront. */
class AdminBusinessController extends Controller
{
    public function __construct(private readonly BusinessProfile $business) {}

    public function show()
    {
        return ApiResponse::ok($this->business->all());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'phone_2' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url', 'max:190'],
            'instagram' => ['nullable', 'string', 'max:190'],
            'facebook' => ['nullable', 'string', 'max:190'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_locale' => ['nullable', Rule::in(['mk', 'sq', 'en'])],
        ]);

        return ApiResponse::ok($this->business->update($data), 200, null, 'Business profile saved');
    }

    /** PNG/JPG logo for emails (Gmail does not show SVG). */
    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);
        $path = $request->file('logo')->store('branding', 'public');
        $url = rtrim((string) config('app.url'), '/') . Storage::url($path);

        return ApiResponse::ok($this->business->update(['logo_url' => $url]), 200, null, 'Logo uploaded');
    }

    /** Public: what the storefront needs to brand itself. */
    public function publicProfile()
    {
        $profile = $this->business->all();

        return ApiResponse::ok(array_intersect_key($profile, array_flip([
            'name', 'tagline', 'email', 'phone', 'phone_2', 'address', 'city', 'country', 'website', 'instagram', 'facebook', 'logo_url', 'brand_color', 'default_locale',
        ])));
    }
}
