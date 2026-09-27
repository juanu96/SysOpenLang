# Translation provider integration

## Built-in OpenAI provider

SysOpenLang includes an optional OpenAI provider. Each site owner supplies their own API key under **SysOpenLang → Advanced settings**. The key is encrypted at rest with the site's WordPress authentication salt and is never shown again in the admin interface.

The translation editor can enqueue automatic translations for standard content, Divi segments, supported ACF text fields, and detected SEO fields. Jobs remain marked as in progress so an editor can review them before publishing.

SysOpenLang uses the OpenAI Responses API with Structured Outputs and sends large pages in bounded batches. The model is configurable in Advanced settings.

## Built-in Claude, Gemini, and Google Translate providers

SysOpenLang also includes providers for the Anthropic Claude API and the Google Gemini API. Both use site-owned API keys, dynamically list models available to the configured account, process long pages in batches, and reuse the same asynchronous Jobs and review workflow.

Claude integration uses the Anthropic Messages API. It does not execute Claude Code or reuse a Claude Code subscription. Gemini integration uses the Gemini `generateContent` API with structured JSON output.

Google Translate integration uses Cloud Translation Basic v2 with its Neural Machine Translation model. It sends bounded batches, requests HTML-aware translation, and restores translated HTML entities before content is saved. Google Cloud billing and the Cloud Translation API must be enabled for the project.

Only one provider is active for new automatic translation jobs at a time. A check mark in the provider tabs identifies it, and the translation editor uses that provider's name on its automatic-translation button. Saving credentials for another provider does not activate it unless **Use this provider for automatic translations** is selected.

A provider is a separate integration that implements `SysOpenLang\Contracts\Translation_Provider`.

```php
final class Example_Provider implements SysOpenLang\Contracts\Translation_Provider {
    public function id() { return 'example'; }
    public function label() { return 'Example provider'; }
    public function is_configured() { return true; }

    public function translate(
        array $segments,
        $source_language,
        $target_language,
        array $context = array()
    ) {
        // Preserve HTML, blocks, shortcodes, placeholders, and protected terms.
        // Return WP_Error on transport, authentication, quota, or format errors.
        return $segments;
    }
}

SysOpenLang\register_provider( new Example_Provider() );
```

The return value must contain `title` and `content`; `excerpt` is optional. SysOpenLang validates the response, sanitizes content with WordPress APIs, records failures, and leaves machine-translated content in an editorial `in-progress` state.

Providers are responsible for credentials, consent notices, rate limits, cost reporting, retry policy, privacy documentation, and preservation of structured content. SysOpenLang core contains no API keys and performs no outbound requests by itself.
