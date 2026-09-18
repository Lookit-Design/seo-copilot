# SEO Copilot text workflow setup

The workflow receives bounded text-generation requests from Lookit SEO Copilot, calls Amazon Bedrock Nova Lite, and returns generated text. Production authentication is required.

## Requirements

- A production n8n instance
- An n8n AWS credential permitted to call `bedrock:InvokeModel` for Amazon Nova Lite
- A random endpoint token containing at least 32 characters

Do not put AWS credentials or the endpoint token in the workflow JSON.

## Setup

1. Generate a token:

   ```sh
   openssl rand -hex 32
   ```

2. Set `BSM_SEO_COPILOT_TOKEN` in the n8n process environment and restart n8n.
3. Import `lookit-seo-copilot-bedrock-text.json`.
4. Open **Bedrock Converse** and select a least-privilege n8n AWS credential.
5. Confirm the Bedrock region and model URL for the AWS account.
6. Save and activate the workflow.
7. Copy the production webhook URL into **SEO Copilot → SEO Settings → AI engine**.
8. Enter the same token in **Text endpoint bearer token** and save.

The workflow rejects a missing, incorrect, or shorter-than-32-character token with HTTP 401. The plugin sends it as `Authorization: Bearer <token>`. Malformed, unsupported, or oversized payloads receive HTTP 400 before Bedrock is called.

## Request and response

The plugin sends one of these tasks:

- `keyphrase`, `subheadings`, and `outline`, which request a JSON array encoded in the response `text` field
- `metadesc`, `title`, and `content`, which request plain text

Payload fields include bounded page context such as `title`, `excerpt`, `category`, `type`, `keyphrase`, `count`, `words`, `target`, `site`, and optional variation context.

A successful response is:

```json
{
  "text": "Generated text or a JSON array string"
}
```

The workflow does not persist prompts or generated text.
