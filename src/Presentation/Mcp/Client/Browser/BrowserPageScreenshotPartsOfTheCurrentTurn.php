<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Browser;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Browser\Agents\BrowserPageScreenshotPart;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;

/**
 * The parts of one page and device that `getWebPageAsScreenshot` already showed in the CURRENT turn, so the tool line
 * can say so: a model whose earlier pictures left its view tends to reopen part 1, 2, 3 again. Read like
 * {@see \DDD\Presentation\Mcp\Client\AI\ToolCallsOfTheCurrentTurn}: the current turn's rows, every call that did not fail or get refused (a failed
 * render showed nothing). The calling row itself is left out by its tool call id; without that id every call that is
 * not COMPLETED yet is left out (the calling row is still pending). A row whose completion has not reached the read
 * set yet still counts. The page is compared by its canonical URL, the device in any letter case. Outside the agent
 * loop there is nothing to read.
 */
class BrowserPageScreenshotPartsOfTheCurrentTurn
{
    public const string TOOL_NAME = 'getWebPageAsScreenshot';

    /** @var int A status line ({preCallMessage}) with at least this many characters beyond the marker counts as a description */
    public const int MIN_DESCRIPTION_CHARS_OF_A_STATUS_LINE = 120;

    /** @return int[] The part numbers already shown, ascending and unique; empty outside the agent loop. */
    public static function findPartNumbersShownInTurn(AgentToolCallProvenance $callProvenance, string $url, string $device): array
    {
        if ($callProvenance->isEvalHarnessCall) {
            return [];
        }
        $conversation = $callProvenance->conversation;
        $messages = $conversation !== null && isset($conversation->messages) ? $conversation->messages : $callProvenance->conversationMessages;
        if (!$messages instanceof AIConversationMessages) {
            return [];
        }
        return self::findPartNumbersShownInMessages($messages, $url, $device, $callProvenance->toolCallId);
    }

    /**
     * The earlier screenshot call of the current turn whose pictures the model has not written about yet: the newest
     * call that did not fail or get refused, when no assistant text (a short status line does not count; one that carries
     * {@see MIN_DESCRIPTION_CHARS_OF_A_STATUS_LINE} characters beyond the marker does) follows it
     * before the calling row. Any page and device count: the pictures of every part compete for the same budget.
     * Null outside the agent loop, in the evaluation harness and when every earlier part was followed by text.
     */
    public static function findUndescribedScreenshotCallOfTurn(AgentToolCallProvenance $callProvenance): ?AIConversationMessage
    {
        if ($callProvenance->isEvalHarnessCall) {
            return null;
        }
        $conversation = $callProvenance->conversation;
        $messages = $conversation !== null && isset($conversation->messages) ? $conversation->messages : $callProvenance->conversationMessages;
        if (!$messages instanceof AIConversationMessages) {
            return null;
        }
        return self::findUndescribedScreenshotCallInMessages($messages, $callProvenance->toolCallId);
    }

    /** The undescribed earlier screenshot call of the current turn of `$messages`, the call `$currentToolCallId` left out. Pure. */
    public static function findUndescribedScreenshotCallInMessages(AIConversationMessages $messages, ?string $currentToolCallId = null): ?AIConversationMessage
    {
        $currentToolCallId = $currentToolCallId !== null && $currentToolCallId !== '' ? $currentToolCallId : null;
        $undescribedScreenshotCall = null;
        foreach ($messages->buildCurrentTurn()->getRows() as $message) {
            if ($message->isAssistantText()) {
                $textBeyondTheMarker = trim(AIConversationMessage::stripPreCallMessageMarker((string)($message->message ?? '')));
                if ($textBeyondTheMarker !== '' && (!$message->isStatusOnlyAnnouncementLine() || mb_strlen($textBeyondTheMarker) >= self::MIN_DESCRIPTION_CHARS_OF_A_STATUS_LINE)) {
                    $undescribedScreenshotCall = null;
                }
                continue;
            }
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall === null || ($toolCall->name ?? null) !== self::TOOL_NAME
                || in_array($toolCall->status, [AIConversationMessage::STATUS_FAILED, AIConversationMessage::STATUS_CANCELLED, AIConversationMessage::STATUS_CANCEL_REQUESTED], true)
                || $toolCall->wasRefusedAsDuplicate || $toolCall->wasRefusedByGate
            ) {
                continue;
            }
            $rowToolCallId = (string)($toolCall->toolCallId ?? '');
            $isTheCallingRow = $currentToolCallId !== null
                ? $rowToolCallId === $currentToolCallId
                : $toolCall->status !== AIConversationMessage::STATUS_COMPLETED;
            if ($isTheCallingRow) {
                break;
            }
            $undescribedScreenshotCall = $message;
        }
        return $undescribedScreenshotCall;
    }

    /** The refusal text for a part opened before the previous one was described. English: it is model-facing. */
    public static function buildDescribeFirstRefusalText(AIConversationMessage $undescribedScreenshotCall): string
    {
        $arguments = $undescribedScreenshotCall->toolCall->arguments ?? [];
        $url = is_string($arguments['url'] ?? null) ? $arguments['url'] : 'the previous page';
        $partNumber = max(1, is_numeric($arguments['part'] ?? null) ? (int)$arguments['part'] : 1);
        return sprintf(
            'Nothing was rendered: describe part %d of %s first. Its pictures leave your view as soon as the next part arrives, '
            . 'so write down now, in a message, what part %d shows (layout, colours, text, images, visual issues); then open the next part. '
            . 'Do not reopen part %d.',
            $partNumber,
            $url,
            $partNumber,
            $partNumber
        );
    }

    /**
     * @return int[] The part numbers of `$url` on `$device` shown in the current turn of `$messages`, the call
     * `$currentToolCallId` left out. Pure.
     */
    public static function findPartNumbersShownInMessages(AIConversationMessages $messages, string $url, string $device, ?string $currentToolCallId = null): array
    {
        $currentToolCallId = $currentToolCallId !== null && $currentToolCallId !== '' ? $currentToolCallId : null;
        $canonicalUrlKey = WebPageMarkdown::canonicalUrlKey($url);
        $partNumbers = [];
        foreach ($messages->buildCurrentTurn()->getRows() as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall === null || ($toolCall->name ?? null) !== self::TOOL_NAME
                || in_array($toolCall->status, [AIConversationMessage::STATUS_FAILED, AIConversationMessage::STATUS_CANCELLED, AIConversationMessage::STATUS_CANCEL_REQUESTED], true)
                || $toolCall->wasRefusedAsDuplicate || $toolCall->wasRefusedByGate
            ) {
                continue;
            }
            $rowToolCallId = (string)($toolCall->toolCallId ?? '');
            $isTheCallingRow = $currentToolCallId !== null
                ? $rowToolCallId === $currentToolCallId
                : $toolCall->status !== AIConversationMessage::STATUS_COMPLETED;
            if ($isTheCallingRow) {
                continue;
            }
            $arguments = $toolCall->arguments;
            $calledUrl = is_string($arguments['url'] ?? null) ? $arguments['url'] : '';
            $calledDevice = is_string($arguments['device'] ?? null) && strtolower(trim($arguments['device'])) === BrowserPageScreenshotPart::DEVICE_MOBILE
                ? BrowserPageScreenshotPart::DEVICE_MOBILE
                : BrowserPageScreenshotPart::DEVICE_DESKTOP;
            if ($calledUrl === '' || $calledDevice !== $device || WebPageMarkdown::canonicalUrlKey($calledUrl) !== $canonicalUrlKey) {
                continue;
            }
            $partNumbers[] = max(1, is_numeric($arguments['part'] ?? null) ? (int)$arguments['part'] : 1);
        }
        $partNumbers = array_values(array_unique($partNumbers));
        sort($partNumbers);
        return $partNumbers;
    }
}
