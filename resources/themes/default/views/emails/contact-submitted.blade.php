New enquiry from the website

Name: {!! $submission->name !!}
Email: {!! $submission->email !!}
Phone: {!! $submission->phone !!}
Subject: {!! $submission->subject !!}

Message:
{!! $submission->message !!}

Sent from: {!! data_get($submission->data, 'page_url') !!}
