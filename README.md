Automatic HTML Translation with AWS - Translate HTML Pages Easily
=================

Introduction
---------

This project takes an HTML file from the user, processes it, and sends the text to Amazon for translation. The translated text is then placed back into the original HTML, providing the user with a translated HTML page.

### Why This Project?

Imagine having a website with thousands of pages and wanting to add a new language (e.g., German). Translating each page manually and creating new pages for each translation would be a huge task. With this project, you don't need to manually translate each page. Simply provide the HTML to this project, and it will deliver a correctly translated page to the user.

Installation
------------

To install this project, follow these steps:

1.  **Clone the repository:**

        git clone https://github.com/username/Translate-PHP.git
        cd Translate-PHP

2.  **Install Composer (if not already installed):**

    For Linux and Mac:

        curl -sS https://getcomposer.org/installer | php
        mv composer.phar /usr/local/bin/composer

    For Windows, download and install Composer from the [official website](https://getcomposer.org/).

3.  **Install dependencies using Composer:**

        composer install


Project Setup
-------------

After installing, you need to configure the project with minimal setup.

1.  **Add required dependencies:**

    Ensure Composer is installed and then run:

        composer install

2.  **Configure project settings in `content_translate.php`:**

   *   **Line 9:** Set the `$key` variable with your Amazon API key.
   *   **Line 10:** Set the `$secret` variable with your Amazon API secret.
   *   **Line 11:** Set the `$region` variable based on your server's region or the region of your users. Amazon provides examples for these regions.

       $key = 'YOUR_AMAZON_API_KEY';
       $secret = 'YOUR_AMAZON_API_SECRET';
       $region = 'YOUR_AMAZON_REGION';

    The first two variables are obtained after creating an account on Amazon and getting the API credentials. 
    The third variable, `$region`, can be set based on the server's location or user location, as provided by Amazon.


Usage
-----

To use this project, after installing and configuring it, send a request to `content_translate.php` with the HTML and target language for translation.

Send these variables using POST:

*   `$_POST['from']`: The language of the HTML to be sent to Amazon.
*   `$_POST['to']`: The target language for Amazon to translate.
*   `$_POST['html']`: The HTML content to be translated.

Example request:

    curl -X POST -d "from=en&to=de&html=<html>Your HTML content</html>" http://yourdomain.com/content_translate.php

By following these steps, you can leverage the power of AWS translation to translate HTML pages on the fly without manual intervention.