# Image Generation

Generate or edit images using a supported provider and model. Provider options control output format, size and other model-specific settings.

## Getting Started

Select a provider and model, then supply a prompt:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('A cute baby sea otter floating on its back in calm blue water')
    ->generate();

$image = $response->firstImage();
file_put_contents('otter.png', base64_decode($image->base64));
```

## Provider Support

Currently, Prism supports image generation through:

- **OpenAI**: GPT Image models
- **Gemini**: conversational image generation and Imagen models
- **Qwen**: Qwen-Image (generation), Qwen-Image-Edit (editing & multi-image fusion)
- [Azure](/providers/azure#embeddings-and-images): image generation on a compatible deployment
- [Replicate](/providers/replicate#supported-features): prediction-based generation with model-specific inputs and image-download host settings
- [xAI](/providers/xai#image-generation): image generation with model-specific format and size options

Choose a model available to your account. Provider support for generation does not imply support for editing or every option. Check the [Gemini](/providers/gemini#image-generation) guide for its image model families and current catalog links.

## Basic Usage

### Simple Generation

The most straightforward way to generate an image:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('A serene mountain landscape at sunset')
    ->generate();

// Access the generated image
$image = $response->firstImage();
if ($image->hasUrl()) {
    echo "Image URL: " . $image->url;
}
if ($image->hasBase64()) {
    echo "Base64 Image Data: " . $image->base64;
}
```

### Working with Responses

The response object provides helpful methods for accessing generated content:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Abstract geometric patterns in vibrant colors')
    ->withProviderOptions(['n' => 2])
    ->generate();

// Check if images were generated
if ($response->hasImages()) {
    echo "Generated {$response->imageCount()} image(s)";

    // Access all images
    foreach ($response->images as $image) {
        if ($image->hasUrl()) {
            echo "Image: {$image->url}\n";
        }

        if ($image->hasBase64()) {
            echo "Base64 Image: " . substr($image->base64, 0, 50) . "...\n";
        }

        if ($image->hasRevisedPrompt()) {
            echo "Revised prompt: {$image->revisedPrompt}\n";
        }
    }

    // Or just get the first one
    $firstImage = $response->firstImage();
}

// Check usage information
echo "Prompt tokens: {$response->usage->promptTokens}";
echo "Model used: {$response->meta->model}";

// Access the raw API response data
$rawResponse = $response->raw;
```

## Provider-Specific Options

While Prism provides a consistent API, you can access provider-specific features using the `withProviderOptions()` method.

### OpenAI Options

OpenAI offers various customization options depending on the model:

#### GPT Image (Base64 Only)

GPT Image models return base64 data. Do not send the older `response_format` parameter:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->withProviderOptions([
        'size' => '1024x1024',              // 1024x1024, 1536x1024, 1024x1536, auto
        'quality' => 'high',                // auto, high, medium, low
        'background' => 'transparent',      // transparent, opaque, auto
        'output_format' => 'png',           // png, jpeg, webp
    ])
    ->generate();

$image = $response->firstImage();
if ($image->hasBase64()) {
    // Save the base64 image to a file
    file_put_contents('generated-image.png', base64_decode($image->base64));
    echo "Base64 image saved to generated-image.png";
}
```

#### Response formats

GPT Image returns base64 data. Use `output_format` to select PNG, JPEG or WebP; omit `response_format`. Other providers can return a URL instead, so inspect `hasBase64()` and `hasUrl()` before saving a result. Follow the [current OpenAI image guide](https://developers.openai.com/api/docs/guides/image-generation) for model options. DALL-E models and `gpt-image-1` have been removed from the API; see [OpenAI's deprecation schedule](https://developers.openai.com/api/docs/deprecations).

#### Image Editing

OpenAI's `gpt-image-2.5-sunburst` model supports editing existing images. Pass your images as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$originalImage = Image::fromLocalPath('photos/landscape.png');

$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Add a vaporwave sunset to the background', [$originalImage])
    ->withProviderOptions([
        'size' => '1024x1024',
        'output_format' => 'png',
        'quality' => 'high',
    ])
    ->generate();

// The edited image is returned as base64
$editedImage = $response->firstImage();
file_put_contents('edited-landscape.png', base64_decode($editedImage->base64));
```

You can edit multiple images at once:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Make the colors more vibrant', [
        Image::fromLocalPath('photo1.png'),
        Image::fromLocalPath('photo2.png')->as('custom-name.png'),
    ])
    ->generate();
```

For precise edits, use a mask to specify which areas to modify:

```php
$response = Prism::image()
    ->using('openai', 'gpt-image-2.5-sunburst')
    ->withPrompt('Replace the sky with a starry night', [
        Image::fromLocalPath('landscape.png'),
    ])
    ->withProviderOptions([
        'mask' => Image::fromLocalPath('sky-mask.png'), // Transparent areas identify the edit region
        'size' => '1024x1024',
        'output_format' => 'png',
    ])
    ->generate();
```

> [!NOTE]
> Use a PNG mask with an alpha channel: transparent pixels identify the area to edit. Keep the mask dimensions aligned with the input. The model uses the mask as guidance, so edits can extend beyond its exact outline.

### Gemini Options

Gemini offers customizations, depending on what model is selected. All Gemini image generation models return base64-encoded images only. They also return `mimeType`.

### Gemini image generation

Gemini conversational image generation provides the option to edit images by passing them as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$originalImage = Image::fromLocalPath('image/boots.png');

$response = Prism::image()
    ->using(Provider::Gemini, 'gemini-2.5-flash-image')
    ->withPrompt('Actually, could we make those boots red?', [$originalImage])
    ->generate();
```

### Imagen Options

```php
$response = Prism::image()
    ->using(Provider::Gemini, 'imagen-4.0-generate-001')
    ->withPrompt('Generate an image of a magnificent building falling into the ocean')
    ->withProviderOptions([
        'n' => 3,                               // number of images to generate
        'size' => '2K',                         // 1K (default), 2K
        'aspect_ratio' => '16:9',               // 1:1 (default), 3:4, 4:3, 9:16, 16:9
        'person_generation' => 'dont_allow',    // dont_allow, allow_adult, allow_all
    ])
    ->generate();
```

### Qwen Options

#### Image Generation

Qwen provides image generation through `qwen-image-max` and `qwen-image-plus` models:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-max')
    ->withPrompt('A cute baby sea otter floating on its back')
    ->withProviderOptions([
        'size' => '1328*1328',                         // Image resolution (width*height)
        'negative_prompt' => 'low quality, blurry',    // Content to avoid
        'prompt_extend' => true,                       // Enable prompt rewriting
        'watermark' => false,                          // Disable watermark
        'seed' => 42,                                  // Random seed for reproducibility
    ])
    ->generate();
```

> [!IMPORTANT]
> Generated image URLs are valid for **24 hours** only. Download and save images promptly.

#### Image Editing

Qwen's `qwen-image-edit-max` and `qwen-image-edit-plus` models support single-image editing and multi-image fusion. Pass your images as the second parameter to `withPrompt()`:

```php
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('Generate an image following this depth map: a red bicycle on a muddy path with dense forest', [
        Image::fromUrl('https://example.com/depth-map.png'),
    ])
    ->withProviderOptions([
        'n' => 2,                              // Output 1-6 images (max/plus models)
        'size' => '1536*1024',                 // Custom output resolution
        'negative_prompt' => 'low quality',    // Content to avoid
        'prompt_extend' => true,               // Enable prompt rewriting
        'watermark' => false,                  // Disable watermark
    ])
    ->generate();
```

For multi-image fusion, pass multiple images — the model can combine elements from up to 3 input images:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('The girl in image 1 wearing the black dress from image 2, sitting in the pose of image 3', [
        Image::fromUrl('https://example.com/person.png'),
        Image::fromUrl('https://example.com/dress.png'),
        Image::fromUrl('https://example.com/pose.png'),
    ])
    ->withProviderOptions([
        'n' => 2,
        'size' => '1024*1536',
    ])
    ->generate();
```

You can also pass images from local files or base64 data:

```php
$response = Prism::image()
    ->using(Provider::Qwen, 'qwen-image-edit-max')
    ->withPrompt('Add a sunset sky to the background', [
        Image::fromLocalPath('/path/to/photo.png'),
    ])
    ->generate();
```

## Input and configuration limits

Pass editing inputs as `Image` objects in the second argument to `withPrompt()`. The image builder discards other media types from that additional-content array. It does not fetch URLs automatically; a provider that needs bytes requires an explicit fetch or local/raw image content.

`withMaxTokens()`, `usingTemperature()` and system-prompt setters are available through shared builder configuration, but these values are not included in the image request sent to its provider. Use the documented provider image options for generation controls.

Catch `PrismException` for unsupported provider actions or invalid provider responses. Wrong or unsupported input media can be refused by the provider-specific mapper. A generated result can contain a URL, base64 data, or both; check availability before saving it. Do not pass a returned URL to an unguarded HTTP fetch without your application's URL policy.

## Testing

Use fakes to test image generation without external API calls:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\ImageResponseFake;

test('can generate images', function () {
    Prism::fake([ImageResponseFake::make()]);

    $response = Prism::image()
        ->using('openai', 'gpt-image-2.5-sunburst')
        ->withPrompt('Test image')
        ->generate();

    expect($response->hasImages())->toBeTrue();
    expect($response->firstImage()->url)->toContain('fake-image.png');
});
```

See the [OpenAI](/providers/openai), [Gemini](/providers/gemini) and [Qwen](/providers/qwen) guides for provider-specific options.
