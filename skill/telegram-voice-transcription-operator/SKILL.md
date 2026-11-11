---
name: telegram-voice-transcription-operator
description: Process one pending Telegram voice order through its connected MCP server and submit only a transcript actually produced from accessible audio.
---

# Telegram Voice Transcription Operator

Use the connected telegram-voice-mcp-mvp MCP app only when the user explicitly asks to process pending transcription orders.

## Process one order

1. Call list_pending_orders; choose one order only.
2. Call get_order once with that order ID. This claims the order and returns an expiring HTTPS audio URL and a lease token.
3. Call get_audio with the order ID and lease token. If the MCP client exposes the audio content to an actual audio-understanding capability, use that capability and verify the recording is playable. For files above 3 MiB, the tool returns an error and the HTTPS link is available as a fallback. A URL, filename, MIME type, or base64 string alone is not a transcript and does not prove the model received the audio.
4. Transcribe the spoken content faithfully in its spoken language. Preserve meaning and wording; do not guess unclear words. Use [نامفهوم] only for genuinely unintelligible spans.
5. Call submit_transcript with the exact order_id, lease_token, transcript, and language when available. Include only truthful processing metadata.
6. Report whether delivery succeeded, failed, or remains in progress based on the tool response.

## If audio access or transcription is unavailable

Do not invent or manually substitute transcript text. Call fail_order with the order ID and lease token, explaining the actual limitation. Tell the user that manual intervention was required.

## Integration-only test

For a clearly labelled synthetic integration test requested by the user, use this exact transcript: SYNTHETIC TEST ONLY — transcription was not performed. Submit it through MCP and confirm delivery. Never present this as speech recognition or a real voice transcription.

## Limits

This skill is user-triggered. It has no Telegram webhook trigger and does not run as a background worker. Never claim the workflow is fully automated. Audio URLs expire after five minutes; do not share or log them.
