<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public demo
    |--------------------------------------------------------------------------
    |
    | Prefix of the shop the landing page's "Try the demo" buttons sign into
    | (e.g. "blg"). Leave empty on installs that serve real shops: the demo
    | routes then return 404 and `sfms:demo-reset` refuses to run.
    |
    */

    'demo_shop' => env('SFMS_DEMO_SHOP'),

    /*
    |--------------------------------------------------------------------------
    | Sales contact
    |--------------------------------------------------------------------------
    |
    | WhatsApp number (international format, digits only) used by the landing
    | page's contact and "Ask for the pricing" buttons.
    |
    */

    'whatsapp' => env('SFMS_WHATSAPP', '923120883979'),

];
