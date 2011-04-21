<?php
require_once "Zotero_Exception.php";
require_once "Mappings.php";
require_once "Feed.php";
require_once "Collections.php";
require_once "Items.php";
require_once "Response.php";
require_once "Item.php";

class Zotero_Library
{
    const ZOTERO_URI = 'https://apidev.zotero.org';
    protected $_apiKey = '';
    protected $_ch = null;
    public $libraryType = null;
    public $libraryID = null;
    public $libraryString = null;
    public $libraryUrlIdentifier = null;
    public $libraryBaseWebsiteUrl = null;
    public $items = null;
    public $collections = null;
    public $dirty = null;
    public $useLibraryAsContainer = true;
    protected $_lastResponse = null;
    
    public function __construct($libraryType, $libraryID, $libraryUrlIdentifier, $apiKey = null, $baseWebsiteUrl="http://www.zotero.org")
    {
        $this->_apiKey = $apiKey;
        if (extension_loaded('curl')) {
            $this->_ch = curl_init();
        } else {
            throw new Exception("You need cURL");
        }
        
        $this->libraryType = $libraryType;
        $this->libraryID = $libraryID;
        $this->libraryString = $this->libraryString($this->libraryType, $this->libraryID);
        $this->libraryUrlIdentifier = $libraryUrlIdentifier;
        
        $this->libraryBaseWebsiteUrl = $baseWebsiteUrl . '/';
        if($this->libraryType == 'group'){
            $this->libraryBaseWebsiteUrl .= 'groups/';
        }
        $this->libraryBaseWebsiteUrl .= $this->libraryUrlIdentifier . '/items';
        
        $this->items = new Zotero_Items();
        $this->collections = new Zotero_Collections();
        $this->collections->libraryUrlIdentifier = $this->libraryUrlIdentifier;
        
        $this->dirty = false;
    }
    
    /**
     * Destructor, closes cURL.
     */
    public function __destruct() {
        curl_close($this->_ch);
    }
    
    public function _request($url, $method="GET", $body=NULL, $headers=array()) {
        echo "url being requested: " . $url . "\n\n";
        $httpHeaders = array();
        foreach($headers as $key=>$val){
            $httpHeaders[] = "$key: $val";
        }
        $ch = $this->_ch;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        $umethod = strtoupper($method);
        switch($umethod){
            case "GET":
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            case "POST":
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                break;
            case "PUT":
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                break;
            case "DELETE":
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                break;
        }
        
        $responseBody = curl_exec($ch);
        $responseInfo = curl_getinfo($ch);
        //echo "{$method} url:" . $url . "\n";
        //echo "%%%%%" . $responseBody . "%%%%%\n\n";
        $zresponse = Zend_Http_Response::fromString($responseBody);
        
        //Zend Response does not parse out the multiple sets of headers returned when curl automatically follows
        //a redirect and the new headers are left in the body. Zend_Http_Client gets around this by manually
        //handling redirects. That may end up being a better solution, but for now we'll just re-read responses
        //until a non-redirect is read
        while($zresponse->isRedirect()){
            $redirectedBody = $zresponse->getBody();
            $zresponse = Zend_Http_Response::fromString($redirectedBody);
        }
        $this->lastResponse = $zresponse;
        return $zresponse;
    }
    
    public function getLastResponse(){
        return $this->_lastResponse;
    }
    
    public static function libraryString($type, $libraryID){
        $lstring = '';
        if($type == 'user') $lstring = 'u';
        elseif($type == 'group') $lstring = 'g';
        $lstring .= $libraryID;
        return $lstring;
    }
    
    /*
     * Requires {target:items|collections|tags, libraryType:user|group, libraryID:<>}
     */
    public function apiRequestUrl($params, $base = Zotero_Library::ZOTERO_URI) {
        //var_dump($params);
        if(!isset($params['target'])){
            throw new Exception("No target defined for api request");
        }
        
        $url = $base . '/' . $this->libraryType . 's/' . $this->libraryID;
        if(isset($params['collectionKey'])){
            $url .= '/collections/' . $params['collectionKey'];
        }
        
        switch($params['target']){
            case 'items':
                $url .= '/items';
                break;
            case 'item':
                if($params['itemKey']){
                    $url .= '/items/' . $params['itemKey'];
                }
                else{
                    $url .= '/items';
                }
                break;
            case 'collections':
                $url .= '/collections';
                break;
            case 'collection':
                break;
            case 'tags':
                $url .= '/tags';
                break;
            case 'children':
                $url .= '/items/' . $params['itemKey'] . '/children';
                break;
            case 'itemTemplate':
                $url = $base . '/items/new';
                break;
            default:
                return false;
        }
        if(isset($params['targetModifier'])){
            switch($params['targetModifier']){
                case 'top':
                    $url .= '/top';
                    break;
            }
        }
        //print "apiRequestUrl: " . $url . "\n";
        return $url;
    }

    public function apiQueryString($passedParams){
        $queryParamOptions = array('start',
                                 'limit',
                                 'order',
                                 'sort',
                                 'content',
                                 'q',
                                 'itemType',
                                 'locale',
                                 'key'
                                 );
        //build simple api query parameters object
        if((!isset($passedParams['key'])) && $this->_apiKey){
            $passedParams['key'] = $this->_apiKey;
        }
        $queryParams = array();
        foreach($queryParamOptions as $i=>$val){
            if(isset($passedParams[$val]) && ($passedParams[$val] != '')) {
                $queryParams[$val] = $passedParams[$val];
            }
        }
        
        //deal with tags specially
        if(isset($passedParams['tag'])){
            if(is_string($passedParams['tag'])){
                $queryParams['tag'] = $passedParams['tag'];
            }
            else{
                //TODO: implement complex tag queries
            }
        }
        
        $queryString = '?';
        $queryParamsArray = array();
        foreach($queryParams as $index=>$value){
            $queryParamsArray[] = urlencode($index) . '=' . urlencode($value);
        }
        $queryString .= implode('&', $queryParamsArray);
        //print "apiQueryString: " . $queryString . "\n";
        return $queryString;
    }
    
    public function parseQueryString($query){
        $params = explode('&', $query);
        $aparams = array();
        foreach($params as $val){
            $t = explode('=', $val);
            $aparams[urldecode($t[0])] = urldecode($t[1]);
        }
        return $aparams;
    }
    
    public function loadAllCollections($params){
        $aparams = array_merge($params, array('target'=>'collections', 'content'=>'json', 'limit'=>100), array('key'=>$this->_apiKey));
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        echo "\n\n";
        do{
            echo "\n\n" . $reqUrl . "\n";
            $reqUrl .= '&key=' . $this->_apiKey;
            $response = $this->_request($reqUrl);
            if($response->isError()){
                throw new Exception("Error fetching collections");
            }
            $body = $response->getRawBody();
            $doc = new DOMDocument();
            $doc->loadXml($body);
            $feed = new Zotero_Feed($doc);
            $entries = $doc->getElementsByTagName("entry");
            foreach($entries as $entry){
                $collection = new Zotero_Collection($entry);
                $this->collections->addCollection($collection);
            }
            if(isset($feed->links['next'])){
                $nextUrl = $feed->links['next']['href'];
                $parsedNextUrl = parse_url($nextUrl);
                $parsedNextUrl['query'] = $this->apiQueryString(array_merge($this->parseQueryString($parsedNextUrl['query']), array('key'=>$this->_apiKey) ) );
                $reqUrl = $parsedNextUrl['scheme'] . '://' . $parsedNextUrl['host'] . $parsedNextUrl['path'] . $parsedNextUrl['query'];
            }
            else{
                $reqUrl = false;
            }
        } while($reqUrl);
        
        $this->collections->loaded = true;
    }
    
    public function loadCollections($params){
        $aparams = array_merge($params, array('target'=>'collections', 'content'=>'json', 'limit'=>100), array('key'=>$this->_apiKey));
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl);
        if($response->isError()){
            throw new Exception("Error fetching collections");
        }
        $body = $response->getRawBody();
        $doc = new DOMDocument();
        $doc->loadXml($body);
        $feed = new Zotero_Feed($doc);
        $entries = $doc->getElementsByTagName("entry");
        foreach($entries as $entry){
            $collection = new Zotero_Collection($entry);
            $this->collections->addCollection($collection);
        }
        if(isset($feed->links['next'])){
            $nextUrl = $feed->links['next']['href'];
            $parsedNextUrl = parse_url($nextUrl);
            $parsedNextUrl['query'] = $this->apiQueryString(array_merge($this->parseQueryString($parsedNextUrl['query']), array('key'=>$this->_apiKey) ) );
            $reqUrl = $parsedNextUrl['scheme'] . '://' . $parsedNextUrl['host'] . $parsedNextUrl['path'] . $parsedNextUrl['query'];
        }
        else{
            $reqUrl = false;
        }
    }
    
    public function loadItemsTop($params=array()){
        $params['targetModifier'] = 'top';
        return $this->loadItems($params);
    }
    
    public function loadItems($params){
        $fetchedItems = array();
        $aparams = array_merge($params, array('target'=>'items', 'content'=>'json'), array('key'=>$this->_apiKey));
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        echo "\n";
        echo $reqUrl . "\n";
        //die;
        $response = $this->_request($reqUrl);
        if($response->isError()){
            throw new Exception("Error fetching items");
        }
        $body = $response->getRawBody();
        $doc = new DOMDocument();
        $doc->loadXml($body);
        $entries = $doc->getElementsByTagName("entry");
        foreach($entries as $entry){
            $item = new Zotero_Item($entry);
            $this->items->addItem($item);
            $fetchedItems[] = $item;
        }
        return $fetchedItems;
    }
    
    public function loadItem($itemKey){
        $aparams = array('target'=>'item', 'content'=>'json', 'itemKey'=>$itemKey);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        
        $response = $this->_request($reqUrl);
        if($response->isError()){
            throw new Exception("Error fetching items");
        }
        
        $body = $response->getRawBody();
        $doc = new DOMDocument();
        $doc->loadXml($body);
        $entries = $doc->getElementsByTagName("entry");
        if(!$entries->length){
            throw new Exception("no item with specified key found");
        }
        else{
            $entry = $entries->item(0);
            $item = new Zotero_Item($entry);
            $this->items->addItem($item);
            return $item;
        }
    }
    
    public function writeUpdatedItem($item){
        if(is_string($item)){
            $itemKey = $item;
            $item = $this->items->getItem($itemKey);
        }
        $updateItemJson = json_encode($item->updateItemObject());
        $etag = $item->etag;
        
        $aparams = array('target'=>'item', 'itemKey'=>$item->itemKey);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'PUT', $updateItemJson, array('If-Match'=>$etag));
        return $response;
    }
    
    public function createItem($item){
        $createItemJson = json_encode(array('items'=>array($item->newItemObject())));;
        //echo $createItemJson;die;
        $aparams = array('target'=>'items');
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'POST', $createItemJson);
        return $response;
    }
    
    public function deleteItem($item){
        $aparams = array('target'=>'item', 'itemKey'=>$item->itemKey);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'DELETE', null, array('If-Match'=>$item->etag));
        return $response;
    }
    
    public function getTemplateItem($itemType){
        $newItem = new Zotero_Item();
        $aparams = array('target'=>'itemTemplate', 'itemType'=>$itemType);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl);
        if($response->isError()){
            throw new Exception("Error with api");
        }
        $itemTemplate = json_decode($response->getRawBody(), true);
        $newItem->apiObject = $itemTemplate;
        return $newItem;
    }
    
    public function createCollection($name, $parent = false){
        $collection = new Zotero_Collection();
        $collection->name = $name;
        $collection->parentCollectionKey = $parent;
        $json = $collection->collectionJson();
        
        $aparams = array('target'=>'collections');
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'POST', $json);
        return $response;
    }
    
    public function removeCollection($collection){
        $aparams = array('target'=>'collection', 'collectionKey'=>$collection->collectionKey);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'DELETE', null, array('If-Match'=>$collection->etag));
        return $response;
    }
    
    public function addItemsToCollection($collection, $items){
        $aparams = array('target'=>'items', 'collectionKey'=>$collection->collectionKey);
        $itemKeysString = '';
        foreach($items as $item){
            $itemKeysString .= $item->itemKey;
        }
        $itemKeysString = trim($itemKeysString);
        
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'POST', $itemKeysString);
        return $response;
    }
    
    public function removeItemsFromCollection($collection, $items){
        $removedItemKeys = array();
        foreach($items as $item){
            $response = $this->removeItemFromCollection($collection, $item);
            if(!$response->isError()){
                $removedItemKeys[] = $item->itemKey;
            }
        }
        return $removedItemKeys;
    }
    
    public function removeItemFromCollection($collection, $item){
        $aparams = array('target'=>'items', 'collectionKey'=>$collection->collectionKey);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'DELETE', null, array('If-Match'=>$collection->etag));
        return $response;
    }
    
    public function writeUpdatedCollection($collection){
        $json = $collection->collectionJson();
        
        $aparams = array('target'=>'collection', 'collectionKey'=>$collection->collectionKey);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'PUT', $json, array('If-Match'=>$collection->etag));
        return $response;
    }
    
    public function trashItem($item){
        $aparams = array('target'=>'item', 'itemKey'=>$item->itemKey);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'DELETE', null, array('If-Match'=>$item->etag));
        return $response;
    }
    
    public function fetchItemChildren($item){
        $aparams = array('target'=>'children', 'itemKey'=>$item->itemKey);
        $reqUrl = $this->apiRequestUrl($aparams) . $this->apiQueryString($aparams);
        $response = $this->_request($reqUrl, 'GET');
        return $response;
    }
    
    public function getItemTypes(){
        $reqUrl = Zotero_Library::ZOTERO_URI . 'itemTypes';
        $response = $this->_request($reqUrl, 'GET');
        if($response->isError()){
            throw new Zotero_Exception("failed to fetch itemTypes");
        }
        $itemTypes = json_decode($response->getBody(), true);
        return $itemTypes;
    }
    
    public function getItemFields(){
        $reqUrl = Zotero_Library::ZOTERO_URI . 'itemFields';
        $response = $this->_request($reqUrl, 'GET');
        if($response->isError()){
            throw new Zotero_Exception("failed to fetch itemFields");
        }
        $itemFields = json_decode($response->getBody(), true);
        return $itemFields;
    }
    
    public function getCreatorTypes($itemType){
        $reqUrl = Zotero_Library::ZOTERO_URI . 'itemTypeCreatorTypes?itemType=' . $itemType;
        $response = $this->_request($reqUrl, 'GET');
        if($response->isError()){
            throw new Zotero_Exception("failed to fetch creatorTypes");
        }
        $creatorTypes = json_decode($response->getBody(), true);
        return $creatorTypes;
    }
    
    public function getCreatorFields(){
        $reqUrl = Zotero_Library::ZOTERO_URI . 'creatorFields';
        $response = $this->_request($reqUrl, 'GET');
        if($response->isError()){
            throw new Zotero_Exception("failed to fetch creatorFields");
        }
        $creatorFields = json_decode($response->getBody(), true);
        return $creatorFields;
    }
    
    public function fetchTags($params){
        
    }
    
    
}

?>