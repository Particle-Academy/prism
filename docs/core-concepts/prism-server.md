# Prism Server

Prism Server exposes registered Prism configurations through an OpenAI-compatible API for use by chat interfaces and other clients.

## How It Works

Prism Server acts as a middleware, translating requests from OpenAI-compatible clients into Prism-specific operations. This means you can use tools like ChatGPT web UIs or any OpenAI SDK to interact with your custom Prism models.

## Setting Up Prism Server

::: danger Protect the endpoints before enabling them
The default `prism_server.middleware` list is empty. Enabling the server without adding authentication and authorization exposes endpoints that spend your provider credentials. Apply your application's authentication, access policy and rate limits before making these routes reachable. The `api` middleware group alone does not authenticate callers.
:::

### 1. Enable Prism Server

First, make sure Prism Server is enabled in your `config/prism.php` file:

```php
return ['prism_server' => [
    // The middleware that will be applied to the Prism Server routes.
    'middleware' => [],
    'enabled' => env('PRISM_SERVER_ENABLED', false),
]];
```

### 2. Register Your Prisms

To make your Prism models available through the server, you need to register them. This is typically done in a service provider, such as `AppServiceProvider`:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\PrismServer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        PrismServer::register(
            'my-custom-model',
            fn () => Prism::text()
                ->using(Provider::Anthropic, 'claude-3-5-sonnet-latest')
                ->withSystemPrompt('You are a helpful assistant.')
        );
    }
}
```

In this example, we're registering a model named `my-custom-model` that uses the Anthropic Claude 3 Sonnet model with a custom system message.

## Using Prism Server

Once set up, Prism Server exposes two main endpoints:

### Chat Completions

To generate text using your registered Prism models:

```bash
curl -X POST "http://your-app.com/prism/openai/v1/chat/completions" \
     -H "Content-Type: application/json" \
     -d '{
  "model": "my-custom-model",
  "messages": [
    {"role": "user", "content": "Hello, who are you?"}
  ]
}'
```

### List Available Models

To get a list of all registered Prism models:

```bash
curl "http://your-app.com/prism/openai/v1/models"
```

## Integration with Open WebUI

Connect an OpenAI-compatible client such as [Open WebUI](https://openwebui.com). Example Docker Compose configuration:

```yaml
services:
  open-webui:
    image: ghcr.io/open-webui/open-webui:main
    ports:
      - "3000:8080"
    environment:
      OPENAI_API_BASE_URLS: "http://laravel:8080/prism/openai/v1"
      WEBUI_SECRET_KEY: "your-secret-key"

  laravel:
    image: serversideup/php:8.3-fpm-nginx
    volumes:
      - ".:/var/www/html"
    environment:
      OPENAI_API_KEY: ${OPENAI_API_KEY}
      ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY}
    depends_on:
      - open-webui
```

With this setup, you can access your Prism models through a user-friendly chat interface at `http://localhost:3000`.

## Adding Middleware

You can add middleware to the Prism Server routes by setting the `middleware` option in your `config/prism.php` file:

```php
// For an application configured to authenticate API tokens with Sanctum:
return ['prism_server' => [
    'middleware' => ['auth:sanctum'],
]];
```
