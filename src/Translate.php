<?php
require __DIR__ . '/../vendor/autoload.php';
use Aws\Translate\TranslateClient;
require_once "src/RedisDB.php";

class Translate{
    private $client;
    public string $toLang = "" ;
    protected string $spChar = " § " ;
    private array $ignoreOperations = [ '+', '-','/','%','×','x','÷','±','=','≠','≈','<','>','≤','≥','∞','(',')'];
    private array $textTags = ['p','h1','h2','h3','h4','h5','h6'];
    private array $ignoreTags = ['i','time','input','textarea','img','html', 'head','body','meta','style','script','link','svg'];
    public function __construct($key,$secret,$reg="ap-southeast-1") {
        $this->client = new TranslateClient([
            'version' => 'latest',
            'region' => $reg,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);
    }

    public function getContent($from,$to,$fullContent)
    {
        $this->toLang = $to;
        $redisConnection = new RedisDB();
        $transHTML = $this->Translate_HTML($from, $to, $fullContent,$redisConnection);
        $transHTML = $this->handleMetaTags($from, $to, $transHTML,$redisConnection);
        return $transHTML;
    }
    public function Translate_HTML($from, $to, $textHTML,$redisConnection){
        $sText  =$sAllText_T = $sTemp = '';
        $insertdbArray = $saveDbArray = $linkList = $modualArray = array();

        $ignoreOperations= $this->ignoreOperations;
        $textTagArray=$this->textTags;
        $ignoreTagz= $this->ignoreTags;

        $TranslatedHTML = $textHTML;
        $sHTML = $this->ob_html_compress($textHTML);
        $sHTML = $this->removeUselessTags($sHTML);

        $docz = new DOMDocument();
        libxml_use_internal_errors(true); // Enable error handling
        $docz->loadHTML('<?xml encoding="UTF-8">' . $sHTML);
        $errors = libxml_get_errors(); // Get any parsing errors
        foreach ($docz->childNodes as $item)
            if ($item->nodeType == XML_PI_NODE)
                $docz->removeChild($item);
        $docz->encoding = 'UTF-8';

        $arrayCounter  =1;
        foreach($docz->getElementsByTagName('*') as $el) {
            $el_tag = $el->tagName;

            //اگر تگهای html و body .. یه سری تگهایی که در آرایه معرفی شده، نباشند، این قسمت اجرا می شود
            if(!in_array($el_tag, $ignoreTagz)){

                if ($el_tag === 'img') {
                    $imageResult=$this->seprateLink($el,$arrayCounter,'src',$linkList,$TranslatedHTML);
                    $TranslatedHTML=$imageResult[0];
                    $linkList=$imageResult[1];
                    $arrayCounter=$imageResult[2];
                }
                if ($el_tag === 'a') {
                    $linkResult=$this->seprateLink($el,$arrayCounter,'href',$linkList,$TranslatedHTML);
                    $TranslatedHTML=$linkResult[0];
                    $linkList=$linkResult[1];
                    $arrayCounter=$linkResult[2];
                }
                //اگر تگ، از نوع تگهای متنی باشد این قسمت اجرا می شود.
                if(in_array($el_tag, $textTagArray))
                {
                    // زیرتگهای،تگ p را حذف می کند و جای آنها کاراکتر خاص را قرار میدهد.
                    $pText=$this->convertP($docz->saveHTML($el));

                    if($pText!='' && (!in_array($pText, $ignoreOperations))){
                        //در قسمت if چک می شود که اگر کاراکتر خاص در جمله موردنظر وجود دارد، براساس کاراکتر خاص، تبدیل به آرایه می کند
                        //ودر خانه های آرایه چک می کند که اگر هر خانه در دیتابیس وجود دارد، ترجمه آن را قرار بده و جمله اصلی رو از متن اصلی پاک کن
                        //و اگر در دیتابیس وجود ندارد به آرایه insertdbArray و متن اصلی اضافه کن
                        if(str_contains($pText,$this->spChar)){
                            $mainArray=explode($this->spChar,$pText);
                            for ($i=0;$i<count($mainArray);$i++){
                                $m_text=trim($mainArray[$i]);
                                if($m_text!='') {
                                    $TransZ = $redisConnection->get($to,$m_text);
                                    $detail_result = $this->checkDBForP($insertdbArray,$TransZ, $m_text, $pText,$sText, $TranslatedHTML, 'with_§',$saveDbArray);
                                    $insertdbArray = $detail_result["insertdbArray"];
                                    $pText = $detail_result["pText"];
                                    $sText = $detail_result["sText"];
                                    $TranslatedHTML = $detail_result["TranslatedHTML"];
                                    $saveDbArray = $detail_result["saveDbArray"];
                                }
                            }
                            //اگر رشته ای وجود داشت، آن را داخل تگ p قرار میدهد و اگر در متنی که برای ترجمه میفرستد، وجود نداشت به آن اضافه می کند.
                            $sText=$this->createText($pText,$sText);
                            $pText='';
                        }
                        else{
                            //این قسمت زمانی اچرا می شود که شامل کاراکتر خاص نباشد
                            $dbtext_search =$pText;
                            $TransZ = $redisConnection->get($to,$dbtext_search);
                            $detail_result = $this->checkDBForP($insertdbArray,$TransZ,'', $pText,$sText, $TranslatedHTML, 'without_§',$saveDbArray);
                            $sText = $detail_result["sText"];
                            $TranslatedHTML = $detail_result["TranslatedHTML"];
                            $saveDbArray = $detail_result["saveDbArray"];
                        }
                    }
                }
                else{
//اگر تگ p نباشد، این قسمت انجام می شود
                    $lengthChild  = $el->childNodes->length;
                    if ($lengthChild>1) {
                        //اگر تگ موردنظر، زیرتگ داشته باشد این قسمت اجرا می شود
                        $directText='';
                        foreach ($el->childNodes as $child) {
                            //تنها متن موجود در تگ اصلی(به غیر از زیرتگ ها) جدا می شود
                            if ($child->nodeType === XML_TEXT_NODE) {
                                // اگر نود فقط یک Text node باشد، محتوای آن را در متغیر directText ذخیره می‌کنیم
                                $directText .= trim($child->nodeValue);
                                $directText=trim($directText);
                                if($directText!='' && (!in_array($directText, $ignoreOperations)) && (!str_contains($directText,"{"))){
                                    //متن اگر در دیتابیس وجود داشت در جای خود جایگذاری می شود ولی اگر وجود نداشت به متن اصلی و آرایه اضافه می شود
                                    $tranzText = $redisConnection->get($to,$directText);
                                    $result=$this->changeContent($directText,$tranzText,$insertdbArray,$sText,$TranslatedHTML,$saveDbArray);
                                    $sText = $result[1];
                                    $TranslatedHTML = $result[2];
                                    $saveDbArray = $result[3];
                                    $directText='';
                                }
                            }
                        }
                        continue;
                    }
                    //اگر تگ موردنظر، تگ باشد و متن نباشد، این قسمت اجرا می شود
                    $childNodeContent=trim($el->textContent);
                    if ($el->nodeType === XML_ELEMENT_NODE) {
                        if($childNodeContent!='' && (!in_array($childNodeContent, $ignoreOperations)) && (!str_contains($childNodeContent,"{"))){
                            //متن اگر در دیتابیس وجود داشت در جای خود جایگذاری می شود ولی اگر وجود نداشت به متن اصلی و آرایه اضافه می شود
                            $tranzText2 = $redisConnection->get($to,$childNodeContent);
                            $result=$this->changeContent($childNodeContent,$tranzText2,$insertdbArray,$sText,$TranslatedHTML,$saveDbArray);
                            $sText = $result[1];
                            $TranslatedHTML = $result[2];
                            $saveDbArray = $result[3];
                        }
                    }
                }
            }
        }
        /*Check Again with Database*/
        if($sText!=''){
            $docz->loadHTML('<?xml encoding="UTF-8">' . $sText);
            foreach($docz->getElementsByTagName('p') as $el) {
                $remove=false;
                $dbst = trim($el->textContent);
                if (str_contains($dbst, '§')) {
                    $each_dbst = explode("§", $dbst);
                    foreach ($each_dbst as $single_dbst) {
                        $TransZ = $redisConnection->get($to,$single_dbst);
                        if ($TransZ){
                            $saveDbArray[] = array($single_dbst, $TransZ);
                            $remove=true;
                        }
                    }
                }else{
                    $TransZ = $redisConnection->get($to,$dbst);
                    if ($TransZ){
                        $saveDbArray[] = array($dbst, $TransZ);
                        $remove=true;
                    }else{
                        if (is_numeric($dbst)){
                            $remove=true;
                        }
                    }
                }
                if ($remove){
                    $sText = str_replace("<p>$dbst</p>","",$sText);
                }

            }
        }
        if($sText!=''){
            $sText="<html lang='$to'><body>".$sText."</body></html>";
            $remind_text=$sText;

            $check_len = true;
            while ($check_len)
            {
                $textLen = strlen($remind_text);
                //طول رشته متن اگر بزرگتر از 9000 بود تا آخرین تگ بسته شدن p قبل از 9000 تقسیم بندی می شود و برای ترجمه فرستاده می شود.
                if ($textLen > 9000)
                {
                    $last_p_position = mb_strrpos(mb_substr($remind_text, 0, 9000), '</p>');

                    if ($last_p_position !== false ) {
                        $substring_length = min($last_p_position, 9000);
                        $sTemp = mb_substr($remind_text, 0, $substring_length);
                        $remind_text=mb_substr($remind_text, $substring_length+1, $textLen);
                    }
                }
                else{
                    $check_len = false;
                    $sTemp = $remind_text;
                }
                if ($sTemp !== ""){
                    $sTemp = mb_convert_encoding($sTemp, 'UTF-8', 'UTF-8');
                    if (strlen($sTemp) > 50){
                        $sAllText_T = $sAllText_T . $this->Translate_String($from, $to, $sTemp);
                    }
                }
            }

            //در این قسمت، در ابتدا متن ها براساس تگ <p> تبدیل به آرایه می شوند و سپس تمام تگ ها از هر سطر حذف می شوند
            $m_paragraphs = array_map(array($this, 'removePTag'), explode('<p>', $sText));
            $t_paragraphs = array_map(array($this, 'removePTag'), explode('<p>', $sAllText_T));

            // تبدیل آرایه‌های اولیه به آرایه دوبعدی
            $translateArray = array_map(null, $m_paragraphs, $t_paragraphs);
            $finalDBArray = $this->handleTextToDB($translateArray);
            foreach ($finalDBArray as $singleArray){
                $redisConnection->ins($to,$singleArray);
            }
            //دو آرایه(یکی آرایه ای که ترجمه شده و دیگری آرایه ای که ترجمه آن در دیتابیس وجود دارد و نیازی به ترجمه نبود)با هم مرج می شوند
            $mainTextArray = array_merge($translateArray, $saveDbArray);
            //آرایه مرج شده براساس طول رشته جمله اصلی مرتب می شوند
            usort($mainTextArray, array($this, 'compareFirstElement'));

            //در این قسمت متن ترجمه شده در فایل اصلی جایگزین میشود
            //در آرایه مرج شده، سطر اول جمله اصلی و سطر دوم جمله ترجمه شده می باشد
            foreach ($mainTextArray as $row) {
                $mainText=trim($row[0]);
                $transText=trim($row[1]);
                if($mainText){
                    $TranslatedHTML = $this->replaceText($mainText," ".$transText." ",$TranslatedHTML);
                }
            }
        }

        //در این قسمت،متن اصلی مربوط به دستورات جوملا در جای خود قرار می گیرند
        $TranslatedHTML = strtr($TranslatedHTML, $modualArray);
        //در این قسمت، متن اصلی مربوط به لینک ها و مسیر عکس ها در جای خود قرار می گیرند
        $TranslatedHTML = strtr($TranslatedHTML, $linkList);

        return $TranslatedHTML;
    }
    public function handleTextToDB($arrayZ)
    {
        $out=[];
        $c = 0;
        foreach ($arrayZ as $item) {
            if ($item[0] and trim($item[0]) !== ""){
                if (str_contains($item[0], "§")){
                    $orgTexts = explode("§",$item[0]);
                    $transTexts = explode("§",$item[1]);

                    for ($count = 0; $count < count($orgTexts); $count++) {
                        if ($orgTexts[$count] and $orgTexts[$count] !== ""){
                            $c++;
                            $out[$c] = $this->addRow(trim(@$orgTexts[$count]),trim(@$transTexts[$count]));
                        }
                    }
                }else{
                    $out[$c] = $this->addRow(trim($item[0]),trim($item[1]));
                    $c++;
                }
            }
        }
        return $out;
    }
    public function handleMetaTags($from, $to, $incomeHTML,$redisConnection)
    {
        $sHTML = $this->ob_html_compress($incomeHTML);
        $docz = new DOMDocument();
        libxml_use_internal_errors(true); // Enable error handling
        $docz->loadHTML('<?xml encoding="UTF-8">' . $sHTML);
        $xp = new domxpath($docz);
        $el_des = $xp->query("//meta[@property='og:description']")[0];
        $el_title = $xp->query("//meta[@property='og:title']")[0];
        $descText = $titleText = "";
        if ($el_des){
            $descText = $el_des->getAttribute("content");
        }
        if ($el_title){
            $titleText = $el_title->getAttribute("content");
        }
        $Tranz_descText = $redisConnection->get($to,$descText);
        $Tranz_titleText = $redisConnection->get($to,$titleText);
        $apiText  = "";
        if (!$Tranz_descText){
            $apiText .= $descText . "$$$";
        }
        if (!$Tranz_titleText){
            $apiText .= $titleText;
        }
        if ($apiText !== ""){
            $transText = $this->Translate_String($from, $to, $apiText);
            if (str_contains($transText,"$$$")){
                $transText_ex = explode("$$$", $transText);
                $Tranz_descText = $transText_ex[0];
                if ($transText_ex[1] !== ""){
                    $Tranz_titleText = $transText_ex[1];
                }
            }else{
                $Tranz_titleText = $transText;
            }
            if ($Tranz_descText){
                $insertdbArray = $this->addRow($descText,$Tranz_descText);
                $redisConnection->ins($to,$insertdbArray);
            }
            if ($Tranz_titleText){
                $insertdbArray = $this->addRow($titleText,$Tranz_titleText);
                $redisConnection->ins($to,$insertdbArray);
            }

        }

        if ($descText !==""){
            $incomeHTML = str_replace("$descText", $Tranz_descText, $incomeHTML);
        }

        if ($titleText !==""){
            $incomeHTML = str_replace("$titleText", $Tranz_titleText, $incomeHTML);
        }

        return $incomeHTML;
    }
    public function Translate_String($from, $to, $text)
    {
        try {
            $result = $this->client->translateText([
                'SourceLanguageCode' => $from,
                'TargetLanguageCode' => $to,
                'Text' => $text,
            ]);
            return $result["TranslatedText"];
        }catch (Exception $e) {
            return '<script>console.log("'. $e->getMessage() . '")</script>';
        }
    }
    public function changeContent($mainText,$TransZ,$insertdbArray,$sText,$TranslatedHTML,$saveDbArray){
        if($TransZ==''){
            $sText=$this->addCountent($mainText,$sText);
            if(!in_array($TransZ, array_column($insertdbArray, 'orgtext')))
                $insertdbArray=$this->addRow($mainText,'');
        }
        else{
            $saveDbArray[] = array($mainText, $TransZ);
        }
        return [$insertdbArray, $sText, $TranslatedHTML,$saveDbArray];
    }
    public function removeUselessTags($sHTML){
        $html = preg_replace('#<style.*?>.*?</style>#is', '', $sHTML);
        $html = preg_replace('#<script.*?>.*?</script>#is', '', $html);
        $html = preg_replace('#<(\w+)(?:\s+[^>]*)?>\s*</\1>#is', '', $html);
        $html = preg_replace('#<(\w+)(?:\s+[^>]*)?>\s*</\1>#is', '', $html);
        return $html;
    }
    public function ob_html_compress($sHTML)
    {
        // three line under,Delete whitespace of file's content(sHTML)
        $search = array(
            '/\>[^\S ]+/s',  // remove whitespaces after tags
            '/[^\S ]+\</s',  // remove whitespaces before tags
            '/(\s)+/s'       // remove multiple whitespace sequences
        );
        $replace = array('>', '<', '\\1');
        $CompressedHTML = preg_replace($search, $replace, $sHTML);

        //two line under,at first,set enter end of each line then separates with enter and set it in the array
        $output = str_replace(array("\r\n", "\r"), "\n", $CompressedHTML);
        $lines = explode("\n", $output);

        //Define array
        $new_lines = array();

        //This loop executed on each line of contents of compressed file
        foreach ($lines as $line) {
            //Check if line is not empty then delete whitespace from the beginning and end of a each line
            if(!empty($line))
                $new_lines[] = trim($line);
        }

        //After delete whitespace,Convert array(each line of contents of compressed file) to string(CompressedHTML)
        $CompressedHTML = implode($new_lines);

        return $CompressedHTML;
    }
    public function addCountent($txtContent,$sText){
        $newText='<p>'.$txtContent.'</p>';
        if(!str_contains($sText,$newText)) $sText .= $newText. PHP_EOL;
        return $sText;
    }
    public function convertP($text){
        /*<p class="font-size16 green-p-color fwnormal"> WhatsApp Bulk Sender <small>Bot Package</small> </p>*/
        $parts = explode('<', $text);
        $result = '';

        foreach ($parts as $part) {
            $end_tag_pos = strpos($part, '>');
            if ($end_tag_pos !== false) {
                $text_before = substr($part, $end_tag_pos + 1);
                if(trim($text_before)!='') $result .= $this->spChar . $text_before;
            } else {
                $result .= $part;
            }
        }
        $result = trim($result, $this->spChar);
        return $result;
    }
    public function replaceText($mainText,$translateText,$TranslatedHTML){
        if(str_contains($mainText,$this->spChar)){
            $mainArray=explode($this->spChar,$mainText);
            $normalizedText = preg_replace('/\s*§\s*/', $this->spChar, trim($translateText));
            $translateArray = explode($this->spChar, $normalizedText);
            if(count($mainArray)>0){
                for ($i=0;$i<count($mainArray);$i++){
                    $m_text=trim($mainArray[$i]);
                    $t_text=(isset($translateArray[$i]))?$translateArray[$i]:'';
                    $TranslatedHTML =$this->replace_html_tags($TranslatedHTML,$m_text,$t_text);
                }
            }
        }
        else{
            $paragraph_to_replace = trim($mainText);
            $new_text = trim($translateText);
            $TranslatedHTML =$this->replace_html_tags($TranslatedHTML,$paragraph_to_replace,$new_text);
        }
        return $TranslatedHTML;
    }
    public function checkDBForP($insertdbArray,$TransZ,$m_text,$pText,$sText,$TranslatedHTML,$type,$saveDbArray){
        if($TransZ==''){
            if($type!='with_§'){
                $sText=$this->createText($pText,$sText);
                $pText='';
            }
        }
        else{
            if($type=='with_§'){
                $iSrcPos = strpos(trim($pText), $m_text);
                if ($iSrcPos !== false) {
                    $pText = str_replace("$m_text ","",$pText);
                    $pText = ltrim($pText,$this->spChar);
                }
                $saveDbArray[] = array($m_text, $TransZ);
            }
            else{
                $saveDbArray[] = array($pText, $TransZ);
            }
        }

        return [
            "insertdbArray" => $insertdbArray,
            "pText" => $pText,
            "sText" => $sText,
            "TranslatedHTML" => $TranslatedHTML,
            "saveDbArray" => $saveDbArray
            ];
    }
    public function createText($pText,$sText){
        $newText = $sTemp ='';
        if($pText!=''){
            if (preg_match('/[a-zA-Z]/', $pText)) {
                $remind_text=$pText;
                $check_len_for_p = true;
                while ($check_len_for_p)
                {
                    $textLen = strlen($remind_text);
                    if ($textLen > 9000)
                    {
                        $last_character_position = mb_strrpos(mb_substr($remind_text, 0, 9000), '§');
                        if ($last_character_position !== false ) {
                            $substring_length = min($last_character_position, 9000);
                            $sTemp = mb_substr($remind_text, 0, $substring_length);
                            $remind_text=mb_substr($remind_text, $substring_length+1, $textLen);
                        }
                    }
                    else {
                        $check_len_for_p = false;
                        $sTemp = $remind_text;
                    }
                    $newText=$newText."<p>".$sTemp."</p>". PHP_EOL;
                }
            }
        }

        if($sText=='') $sText .= $newText;
        else if(!str_contains($sText,$newText)) $sText .= $newText;
        return $sText;
    }
    public function addRow($maintxt,$translate)
    {
        return [
            'orgtext' => $maintxt,
            'translate' => $translate
        ];
    }
    public function compareFirstElement($a, $b) {
        return strlen($b[0]) - strlen($a[0]);
    }
    public function removePTag($string) {
        $string=strip_tags($string);
        $string = preg_replace('/<\/?[a-z][^>]*>|\/\s*p\s*>/i', '', $string);
        return $string;
    }
    public function seprateLink($el,$arrayCounter,$attType,$linkList,$mainHtml){
        $link = $el->getAttribute($attType);
        if($link!=''){
            $tmpText='@'.$arrayCounter;
            $linkList[$tmpText] =$link;
            $mainHtml=str_replace($link, $tmpText, $mainHtml);
            $arrayCounter++;
        }
        return [$mainHtml,$linkList,$arrayCounter];
    }
    public function replace_html_tags($html_string, $search_text, $replace_text){
        $search_text_escaped = preg_quote($search_text, '/');
        $search_text_escaped = str_replace('\.','\.\s*',$search_text_escaped);
        $search_text_escaped = str_replace('\;','\;\s*',$search_text_escaped);
        $search_text_escaped = str_replace('\,','\,\s*',$search_text_escaped);
        $search_text_escaped = str_replace('\:','\:\s*',$search_text_escaped);
        $search_text_escaped = str_replace("\'","\'\s*",$search_text_escaped);
        $pattern = '/(?<=>)([^<]*?)' . $search_text_escaped . '([^<]*?)(?=<\/?\w+[^>]*>)/i';

        $new_html_string = preg_replace_callback($pattern, function ($matches) use ($search_text_escaped, $replace_text) {
            $tag_content = trim($this->ob_html_compress($matches[0]));
            $search_text_escaped = str_replace('\\', '', $search_text_escaped);
            $search_text_escaped = str_replace('s*', '', $search_text_escaped);

            if (strlen($tag_content) == strlen(trim($search_text_escaped))) {
                return $replace_text;
            } else {
                return $matches[0];
            }
        }, $html_string);

        return $new_html_string;
    }
}
