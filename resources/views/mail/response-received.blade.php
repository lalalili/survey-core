<x-mail::message>
# 收到一筆新的問卷回應

問卷：**{{ $surveyTitle }}**

| 欄位 | 內容 |
|------|------|
| 回應 ID | #{{ $responseId }} |
| 填答編號 | {{ $responseNumber ?? '—' }} |
| 提交時間 | {{ $submittedAt ?? '—' }} |
| 收件人姓名 | {{ $recipientName ?? '匿名' }} |
| 收件人 Email | {{ $recipientEmail ?? '—' }} |
@if($collectorName)
| 來源 Collector | {{ $collectorName }} |
@endif

<x-mail::button :url="config('app.url') . '/admin/survey-responses/' . $responseId">
前往後台查看回應
</x-mail::button>

感謝，<br>
{{ config('app.name') }} 問卷系統
</x-mail::message>
