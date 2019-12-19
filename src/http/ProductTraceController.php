<?php

namespace Increment\Marketplace\Http;

use Illuminate\Http\Request;
use App\Http\Controllers\APIController;
use Increment\Marketplace\Models\ProductTrace;
use Increment\Marketplace\Models\BundledProduct;
use Increment\Marketplace\Models\TransferredProduct;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
class ProductTraceController extends APIController
{

  public $productController = 'Increment\Marketplace\Http\ProductController';
  public $transferController = 'Increment\Marketplace\Http\TransferController';
  public $bundledProductController = 'Increment\Marketplace\Http\BundledProductController';
  public $bundledSettingController = 'Increment\Marketplace\Http\BundledSettingController';
  function __construct(){
  	$this->model = new ProductTrace();

    $this->notRequired = array(
      'rf', 'nfc', 'manufacturing_date', 'batch_number'
    );
  }

  public function getByParams($column, $value){
    $result  = ProductTrace::where($column, '=', $value)->orderBy('created_at', 'desc')->limit(5)->get();
    if(sizeof($result) > 0){
      $i = 0;
      foreach ($result as $key) {
        $result[$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz('Asia/Manila')->format('F j, Y h:i A');
        $i++;
      }
    }
    return sizeof($result) > 0 ? $result : null;
  }

  public function retrieve(Request $request){
    $data = $request->all();
    $product = app($this->productController)->getProductByParams('code', $data['code']);

    if($product != null){
      $data['condition'][] = array(
        'column'  => 'product_id',
        'clause'  => '=',
        'value'   => $product['id']
      );
    }

    $this->model = new ProductTrace();
    $this->retrieveDB($data);

    $i = 0;
    foreach ($this->response['data'] as $key) {
      $item = $this->response['data'][$i];
      $this->response['data'][$i]['product'] = $product;
      // $this->response['data'][$i]['manufacturing_date_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $item['manufacturing_date'])->copy()->tz('Asia/Manila')->format('F j, Y H:i A');
      $this->response['data'][$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $item['created_at'])->copy()->tz('Asia/Manila')->format('F j, Y h:i A');
      $i++;
    }
    $this->response['datetime_human'] = Carbon::now()->copy()->tz('Asia/Manila')->format('F j Y h i A');
    return $this->response();
  }

  public function retrieveByParams(Request $request){
    $data = $request->all();
    $this->model = new ProductTrace();
    $this->retrieveDB($data);
    $i = 0;
    foreach ($this->response['data'] as $key) {
      $item = $this->response['data'][$i];
      $this->response['data'][$i]['product'] = app($this->productController)->getProductByParams('id', $item['product_id']);
      $item = $this->response['data'][$i];
      if($this->checkOwnProduct($item, $data['merchant_id']) == false){
        $this->response['data'] = null;
        $this->response['error'] = 'You don\'t own this product!';
        return $this->response();
      }
      $this->response['data'][$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $item['created_at'])->copy()->tz('Asia/Manila')->format('F j, Y h:i A');
      $this->response['data'][$i]['bundled_product'] = app($this->bundledProductController)->getByParams('product_trace', $item['id']);
      if($this->response['data'][$i]['product'] != null){
        $type = $this->response['data'][$i]['product']['type'];
        $this->response['data'][$i]['product']['qty'] = null;
        if($data['account_type'] == 'MANUFACTURER' || $type == 'bundled'){
          $this->response['data'][$i]['product']['qty'] = $this->getBalanceQty('product_id', $item['product_id'], 'active');  
        }else{
          $this->response['data'][$i]['product']['qty'] = app($this->transferController)->getQtyTransferred($data['merchant_id'], $item['product_id']);
        }
        if($type == 'bundled'){
          $bundled = $this->response['data'][$i]['product']['id'];
          $this->response['data'][$i]['product']['bundled_status'] = app($this->bundledSettingController)->getStatusByProductTrace($bundled, $item['id']);
        }
      }
      $i++;
    }
    return $this->response();
  }

  public function checkOwnProduct($trace, $merchantId){
    $result = app($this->transferController)->getOwn($trace['id']);
    if($result){
      if(intval($result->to) == intval($merchantId)){
        return true;
      }
    }else{
      if(intval($trace['product']['merchant_id']) == intval($merchantId)){
        return true;
      }
    }
    return false;
  }

  public function retrieveByBundled(Request $request){
    $data = $request->all();
    $this->model = new ProductTrace();
    $this->retrieveDB($data);
    $i = 0;
    foreach ($this->response['data'] as $key) {
      $item = $this->response['data'][$i];
      $this->response['data'][$i]['product'] = app($this->productController)->getByParams('id', $item['product_id']);
      $item = $this->response['data'][$i];
      $bundledTrace = $data['bundled_trace'];
      $productTrace = $item['id'];
      if($this->checkOwnProduct($item, $data['merchant_id']) == false){
        $this->response['data'] = null;
        $this->response['error'] = 'You don\'t own this product!';
        return $this->response();
      }
      $this->response['data'][$i]['bundled_product'] = app($this->bundledProductController)->getByParams('product_trace', $item['id']);
      $this->response['data'][$i]['exist_flag'] = app($this->bundledProductController)->checkIfExist($bundledTrace, $productTrace);
      if($this->response['data'][$i]['product'] != null){
        // $this->response['data'][$i]['product']['qty'] = $this->getBalanceQty('product_id', $item['product_id']);
        $merchant = intval($item['product']['merchant_id']);
        if($data['account_type'] == 'MANUFACTURER' || $merchant == intval($data['merchant_id'])){
          $this->response['data'][$i]['product']['qty'] = $this->getBalanceQty('product_id', $item['product_id'], 'active');
        }else{
          $this->response['data'][$i]['product']['qty'] = app($this->transferController)->getQtyTransferred($data['merchant_id'], $item['product_id']);
        }
      }
      $i++;
    }
    
    return $this->response();
  }

  public function getByParamsDetails($column, $value){
    $result  = ProductTrace::where($column, '=', $value)->get();
    if(sizeof($result) > 0){
      $i = 0;
      foreach ($result as $key) {
        $item = $result[$i];
        $result[$i]['product'] = app($this->productController)->getByParams('id', $item['product_id']);
        $result[$i]['created_at_human'] = Carbon::createFromFormat('Y-m-d H:i:s', $result[$i]['created_at'])->copy()->tz('Asia/Manila')->format('F j, Y h:i A');
        $i++;
      }
    }
    return sizeof($result) > 0 ? $result : null;
  }

  public function getBalanceQty($column, $value, $flag = 'active'){
    $result  = ProductTrace::where($column, '=', $value)->where('status', '=', $flag)->get();
    $counter = 0;
    if(sizeof($result) > 0){
      $i = 0;
      foreach ($result as $key) {
        $item = $result[$i];
        $bundled = BundledProduct::where('product_trace', '=', $item['id'])->where('deleted_at', '=', null)->get();

        $transferred = TransferredProduct::where('payload_value', '=', $item['id'])->where('deleted_at', '=', null)->get();

        if(sizeof($bundled) == 0 && sizeof($transferred) == 0){
          $counter++;
        }
        $i++;
      }
    }
    return $counter;
  }

  public function create(Request $request){
    $data = $request->all();
    $qty = intval($data['qty']);
    for ($i=0; $i < $qty; $i++) {
      $data['code'] = $this->generateCode();
      $data['status'] = 'inactive';
      $this->model = new ProductTrace();
      $this->insertDB($data);
    }
    return $this->response();
  }

  public function generateNFC($productId, $data){
    $product = app($this->productController)->retrieveProductById($productId, $data['account_id'], $data['inventory_type']);
    // product trace code
    $id = $product['code'].'/0/';
    // $merchantName = $product['merchant']['name'].'/0/';
    // $title = $product['title'].'/0/';
    $batchNumber = $data['batch_number'].'/0/';
    $manufacturingDate = $data['manufacturing_date'].'/0/';
    // $link = 'https://www.traceag.com.au/product/'.$product['code'].'/0/';
    return Hash::make($id.$batchNumber.$manufacturingDate);
    // product id
    // trace id
    // merchant name
    // product title
    // payload
    // batch number
    // manufacturing date
    // website
    // delimiter = 0
    // generate code for nfc
  }

  public function generateCode(){
    $code = substr(str_shuffle("0123456789012345678901234567890123456789"), 0, 32);
    $codeExist = ProductTrace::where('code', '=', $code)->get();
    if(sizeof($codeExist) > 0){
      $this->generateCode();
    }else{
      return $code;
    }
  }
  
  public function linkTags(Request $request){
    //
  }

  public function update(Request $request){
    $data = $request->all();
    $result = ProductTrace::where('rf', '=', $data['rf'])->get();
    if(sizeof($result) > 0){
      $this->response['data'] = null;
      $this->response['error'] = 'Drum tag is already used!';
    }else{
      $this->model = new ProductTrace();
      $this->updateDB($data);
      $this->response['data'] = $data['id'];
    }
    return $this->response();
  }
}
