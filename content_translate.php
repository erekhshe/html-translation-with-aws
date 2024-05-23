<?php
require  "src/Translate.php";

if (isset($_POST)){
    $inp_html = $_POST['html'];
    if ($inp_html !== ""){
        $fromlang = $_POST['from'];
        $tolang = $_POST['to'];
        $key = "YOUR-AWS-KEY-HERE";
        $secret = "YOUR-AWS-SECRET-HERE";
        $region = "REGION-HERE";

        $translate = new Translate($key,$secret,$region);
        $translatedHtml = $translate->getContent($fromlang,$tolang,$inp_html);
        return $translatedHtml;
    }
}
