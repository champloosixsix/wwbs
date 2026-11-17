<?php

// The contact form.
//
// The work is done by wc-form/handler.php, the same file every site
// webChamploo builds uses: it saves the message, hands it to the
// webChamploo app (which checks it for spam and emails the branded copy),
// and falls back to a plain email if the app cannot be reached. This file
// only says what the form asks.
//
// The page's form script (assets/vendor/php-email-form/validate.js) treats
// the plain word OK as success and shows anything else as the error, so
// this form answers in text. That script and the page are unchanged.

declare(strict_types=1);

require __DIR__ . '/../wc-form/handler.php';

wc_form_handle([
    'name' => 'Contact form',
    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'subject' => 'Subject',
        'message' => 'Message',
    ],
    'required' => ['name', 'email', 'subject', 'message'],
    'respond' => 'text',
]);
