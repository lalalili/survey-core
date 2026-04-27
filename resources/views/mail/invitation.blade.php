<x-mail::message>
# 您好，{{ $recipientName }}

感謝您參與我們的問卷調查！

請點擊下方連結填寫問卷 **{{ $surveyTitle }}**：

<x-mail::button :url="$surveyUrl">
填寫問卷
</x-mail::button>

此連結為您的專屬填寫連結，請勿轉發給他人。

若您對問卷有任何疑問，請回覆此郵件聯繫我們。

感謝您的寶貴意見！

{{ config('app.name') }}
</x-mail::message>
