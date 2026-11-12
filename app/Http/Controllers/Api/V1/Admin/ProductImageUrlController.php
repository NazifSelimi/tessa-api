<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Adds a product photo from a web address. The file is downloaded and then
 * saved exactly like an uploaded file (ProductImageController::store), so it
 * is added to the gallery and never replaces an existing photo.
 */
class ProductImageUrlController extends Controller
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __invoke(Request $request, Product $product, ProductImageController $images)
    {
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:2000']]);
        $body = $this->download($data['url']);

        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, $body);
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            abort_unless($ext, 422, 'That address is not a JPG, PNG or WebP image.');
            $file = new UploadedFile($tmp, "from-url.$ext", $mime, null, true);
            $upload = Request::create('', 'POST', ['alt' => $product->name], [], ['image' => $file]);
            $upload->setUserResolver(fn () => $request->user());

            return $images->store($upload, $product);
        } finally {
            @unlink($tmp);
        }
    }

    /** Fetch a public http(s) URL, refusing private/internal addresses (also after redirects). */
    private function download(string $url): string
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $this->assertPublic($url);
            $response = Http::timeout(15)->withOptions(['allow_redirects' => false])
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (product photo import)', 'Accept' => 'image/*'])
                ->get($url);
            if ($response->redirect() && $response->header('Location')) {
                $url = (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($response->header('Location')));
                continue;
            }
            abort_unless($response->successful(), 422, 'The image could not be downloaded (HTTP ' . $response->status() . ').');
            $body = $response->body();
            abort_if(strlen($body) > self::MAX_BYTES, 422, 'The image is larger than 10 MB.');

            return $body;
        }
        abort(422, 'Too many redirects.');
    }

    private function assertPublic(string $url): void
    {
        $parts = parse_url($url);
        abort_unless(in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && !empty($parts['host']), 422, 'Use an http or https image address.');
        $ips = gethostbynamel($parts['host']) ?: [];
        abort_if($ips === [], 422, 'That address could not be found.');
        foreach ($ips as $ip) {
            abort_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), 422, 'That address is not allowed.');
        }
    }
}
