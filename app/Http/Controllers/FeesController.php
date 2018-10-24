<?php

namespace App\Http\Controllers;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\MultipleQueue;
use PDO;
use DB;
class FeesController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     *
     */

    public function __construct()
    {
        $this->middleware('auth');

    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
		$date_from=date('Y-m-d',strtotime('-90 days'));		
		$date_to=date('Y-m-d');	
	
		$teams= DB::select('select bg,bu from asin group by bg,bu ORDER BY BG ASC,BU ASC');

        return view('fees/index',['date_from'=>$date_from ,'date_to'=>$date_to,'teams'=>$teams,'accounts'=>$this->getSellerId(),'users'=>$this->getUsers()]);
		

    }
	
	public function getSellerId(){
		$seller=[];
		$accounts= DB::connection('order')->table('accounts')->where('status',1)->groupBy(['sellername','sellerid'])->get(['sellername','sellerid']);
		$accounts=json_decode(json_encode($accounts), true);
		foreach($accounts as $account){
			$seller[$account['sellerid']]=$account['sellername'];
		}
		return $seller;
	}
	
	public function getUsers(){
        $users = User::get()->toArray();
        $users_array = array();
        foreach($users as $user){
            $users_array[$user['id']] = $user['name'];
        }
        return $users_array;
    }

    
	
    public function getads(Request $request)
    {
		$orderby = $request->input('order.0.column',1);
		if($orderby==6){
			$orderby = 'TransactionValue';
		}else{
			$orderby = 'PostedDate';
		}
        $sort = $request->input('order.0.dir','desc');
        if ($request->input("custombgbu") && $request->input("customActionType") == "group_action") {
			   $updateDate = [];
               $bgbu = $request->input('custombgbu');
			   $bgbu_arr = explode('_',$bgbu);
			   if(array_get($bgbu_arr,0)) $updateDate['bg'] = array_get($bgbu_arr,0);
			   if(array_get($bgbu_arr,1)) $updateDate['bu'] = array_get($bgbu_arr,1);
			   $updateDate['user_id'] = Auth::user()->id;
			    DB::connection('order')->table('finances_product_ads_payment_event')->whereIn('id',$request->input("id"))->update($updateDate);
        }
		$date_from=$request->input('date_from')?$request->input('date_from'):date('Y-m-d',strtotime('- 90 days'));
        $date_to=$request->input('date_to')?$request->input('date_to'):date('Y-m-d');
		
		$datas= DB::connection('order')->table('finances_product_ads_payment_event')->where('PostedDate','>=',$date_from.'T00:00:00Z')->where('PostedDate','<=',$date_to.'T23:59:59Z');
               
        if($request->input('sellerid')){
            $datas = $datas->where('SellerId', $request->input('sellerid'));
        }
		
		if($request->input('bgbu')){
			   $bgbu = $request->input('bgbu');
			   $bgbu_arr = explode('_',$bgbu);
			   if(count($bgbu_arr)>1){
			   	if(array_get($bgbu_arr,0)) $datas = $datas->where('bg',array_get($bgbu_arr,0));
			   	if(array_get($bgbu_arr,1)) $datas = $datas->where('bu',array_get($bgbu_arr,1));
			   }else{
			   		$datas = $datas->whereNull('bg');
			   }
		}
		if($request->input('user_id')){
            $datas = $datas->where('user_id', $request->input('user_id'));
        }
		
		if($request->input('invoiceid')){
            $datas = $datas->where('InvoiceId', $request->input('invoiceid'));
        }
		$iTotalRecords = $datas->count();
        $iDisplayLength = intval($_REQUEST['length']);
        $iDisplayLength = $iDisplayLength < 0 ? $iTotalRecords : $iDisplayLength;
        $iDisplayStart = intval($_REQUEST['start']);
        $sEcho = intval($_REQUEST['draw']);
		$lists =  $datas->orderBy($orderby,$sort)->offset($iDisplayStart)->limit($iDisplayLength)->get()->toArray();
        $records = array();
        $records["data"] = array();

        $end = $iDisplayStart + $iDisplayLength;
        $end = $end > $iTotalRecords ? $iTotalRecords : $end;
		$accounts = $this->getSellerId();
		$users= $this->getUsers();
		$lists=json_decode(json_encode($lists), true);
		foreach ( $lists as $list){
            $records["data"][] = array(
                '<label class="mt-checkbox mt-checkbox-single mt-checkbox-outline"><input name="id[]" type="checkbox" class="checkboxes" value="'.$list['Id'].'"/><span></span></label>',
                $list['PostedDate'],
				array_get($accounts,$list['SellerId']),
				$list['InvoiceId'],
				$list['bg'].' - '.$list['bu'],
				array_get($users,$list['user_id'],''),
				$list['TransactionValue'].' '.$list['Currency'],
            );
		}
        $records["draw"] = $sEcho;
        $records["recordsTotal"] = $iTotalRecords;
        $records["recordsFiltered"] = $iTotalRecords;
        echo json_encode($records);
    }
	
	
	
	public function getdeal(Request $request)
    {
		$orderby = $request->input('order.0.column',1);
		if($orderby==6){
			$orderby = 'TotalAmount';
		}else{
			$orderby = 'PostedDate';
		}
        $sort = $request->input('order.0.dir','desc');
        if ($request->input("custombgbu") && $request->input("customActionType") == "group_action") {
			   $updateDate = [];
               $bgbu = $request->input('custombgbu');
			   $bgbu_arr = explode('_',$bgbu);
			   if(array_get($bgbu_arr,0)) $updateDate['bg'] = array_get($bgbu_arr,0);
			   if(array_get($bgbu_arr,1)) $updateDate['bu'] = array_get($bgbu_arr,1);
			   $updateDate['user_id'] = Auth::user()->id;
			    DB::connection('order')->table('finances_deal_event')->whereIn('id',$request->input("id"))->update($updateDate);
        }
		$date_from=$request->input('date_from')?$request->input('date_from'):date('Y-m-d',strtotime('- 90 days'));
        $date_to=$request->input('date_to')?$request->input('date_to'):date('Y-m-d');
		
		$datas= DB::connection('order')->table('finances_deal_event')->where('PostedDate','>=',$date_from.'T00:00:00Z')->where('PostedDate','<=',$date_to.'T23:59:59Z');
               
        if($request->input('sellerid')){
            $datas = $datas->where('SellerId', $request->input('sellerid'));
        }
		
		if($request->input('bgbu')){
			   $bgbu = $request->input('bgbu');
			   $bgbu_arr = explode('_',$bgbu);
			   if(count($bgbu_arr)>1){
			   	if(array_get($bgbu_arr,0)) $datas = $datas->where('bg',array_get($bgbu_arr,0));
			   	if(array_get($bgbu_arr,1)) $datas = $datas->where('bu',array_get($bgbu_arr,1));
			   }else{
			   		$datas = $datas->whereNull('bg');
			   }
		}
		if($request->input('user_id')){
            $datas = $datas->where('user_id', $request->input('user_id'));
        }
		
		if($request->input('feedes')){
            $datas = $datas->where('DealDescription','like','%'.$request->input('feedes').'%');
        }
		$iTotalRecords = $datas->count();
        $iDisplayLength = intval($_REQUEST['length']);
        $iDisplayLength = $iDisplayLength < 0 ? $iTotalRecords : $iDisplayLength;
        $iDisplayStart = intval($_REQUEST['start']);
        $sEcho = intval($_REQUEST['draw']);
		$lists =  $datas->orderBy($orderby,$sort)->offset($iDisplayStart)->limit($iDisplayLength)->get()->toArray();
        $records = array();
        $records["data"] = array();

        $end = $iDisplayStart + $iDisplayLength;
        $end = $end > $iTotalRecords ? $iTotalRecords : $end;
		$accounts = $this->getSellerId();
		$users= $this->getUsers();
		$lists=json_decode(json_encode($lists), true);
		foreach ( $lists as $list){
            $records["data"][] = array(
                '<label class="mt-checkbox mt-checkbox-single mt-checkbox-outline"><input name="id[]" type="checkbox" class="checkboxes" value="'.$list['Id'].'"/><span></span></label>',
                $list['PostedDate'],
				array_get($accounts,$list['SellerId']),
				$list['DealDescription'],
				$list['bg'].' - '.$list['bu'],
				array_get($users,$list['user_id'],''),
				$list['TotalAmount'].' '.$list['Currency'],
            );
		}
        $records["draw"] = $sEcho;
        $records["recordsTotal"] = $iTotalRecords;
        $records["recordsFiltered"] = $iTotalRecords;
        echo json_encode($records);
    }
	
	
	public function getcoupon(Request $request)
    {
		$orderby = $request->input('order.0.column',1);
		if($orderby==6){
			$orderby = 'TotalAmount';
		}else{
			$orderby = 'PostedDate';
		}
        $sort = $request->input('order.0.dir','desc');
        if ($request->input("custombgbu") && $request->input("customActionType") == "group_action") {
			   $updateDate = [];
               $bgbu = $request->input('custombgbu');
			   $bgbu_arr = explode('_',$bgbu);
			   if(array_get($bgbu_arr,0)) $updateDate['bg'] = array_get($bgbu_arr,0);
			   if(array_get($bgbu_arr,1)) $updateDate['bu'] = array_get($bgbu_arr,1);
			   $updateDate['user_id'] = Auth::user()->id;
			    DB::connection('order')->table('finances_coupon_event')->whereIn('id',$request->input("id"))->update($updateDate);
        }
		$date_from=$request->input('date_from')?$request->input('date_from'):date('Y-m-d',strtotime('- 90 days'));
        $date_to=$request->input('date_to')?$request->input('date_to'):date('Y-m-d');
		
		$datas= DB::connection('order')->table('finances_coupon_event')->where('PostedDate','>=',$date_from.'T00:00:00Z')->where('PostedDate','<=',$date_to.'T23:59:59Z');
               
        if($request->input('sellerid')){
            $datas = $datas->where('SellerId', $request->input('sellerid'));
        }
		
		if($request->input('bgbu')){
			   $bgbu = $request->input('bgbu');
			   $bgbu_arr = explode('_',$bgbu);
			   if(count($bgbu_arr)>1){
			   	if(array_get($bgbu_arr,0)) $datas = $datas->where('bg',array_get($bgbu_arr,0));
			   	if(array_get($bgbu_arr,1)) $datas = $datas->where('bu',array_get($bgbu_arr,1));
			   }else{
			   		$datas = $datas->whereNull('bg');
			   }
		}
		if($request->input('user_id')){
            $datas = $datas->where('user_id', $request->input('user_id'));
        }
		
		if($request->input('feedes')){
            $datas = $datas->where('SellerCouponDescription','like','%'.$request->input('feedes').'%');
        }
		$iTotalRecords = $datas->count();
        $iDisplayLength = intval($_REQUEST['length']);
        $iDisplayLength = $iDisplayLength < 0 ? $iTotalRecords : $iDisplayLength;
        $iDisplayStart = intval($_REQUEST['start']);
        $sEcho = intval($_REQUEST['draw']);
		$lists =  $datas->orderBy($orderby,$sort)->offset($iDisplayStart)->limit($iDisplayLength)->get()->toArray();
        $records = array();
        $records["data"] = array();

        $end = $iDisplayStart + $iDisplayLength;
        $end = $end > $iTotalRecords ? $iTotalRecords : $end;
		$accounts = $this->getSellerId();
		$users= $this->getUsers();
		$lists=json_decode(json_encode($lists), true);
		foreach ( $lists as $list){
            $records["data"][] = array(
                '<label class="mt-checkbox mt-checkbox-single mt-checkbox-outline"><input name="id[]" type="checkbox" class="checkboxes" value="'.$list['Id'].'"/><span></span></label>',
                $list['PostedDate'],
				array_get($accounts,$list['SellerId']),
				$list['SellerCouponDescription'],
				$list['bg'].' - '.$list['bu'],
				array_get($users,$list['user_id'],''),
				$list['TotalAmount'].' '.$list['Currency'],
            );
		}
        $records["draw"] = $sEcho;
        $records["recordsTotal"] = $iTotalRecords;
        $records["recordsFiltered"] = $iTotalRecords;
        echo json_encode($records);
    }
	
	public function getservice(Request $request)
    {
		$orderby = $request->input('order.0.column',1);
		if($orderby==6){
			$orderby = 'Amount';
		}else{
			$orderby = 'PostedDate';
		}
        $sort = $request->input('order.0.dir','desc');
        if ($request->input("custombgbu") && $request->input("customActionType") == "group_action") {
			   $updateDate = [];
               $bgbu = $request->input('custombgbu');
			   $bgbu_arr = explode('_',$bgbu);
			   if(array_get($bgbu_arr,0)) $updateDate['bg'] = array_get($bgbu_arr,0);
			   if(array_get($bgbu_arr,1)) $updateDate['bu'] = array_get($bgbu_arr,1);
			   $updateDate['user_id'] = Auth::user()->id;
			    DB::connection('order')->table('finances_servicefee_event')->whereIn('id',$request->input("id"))->update($updateDate);
        }
		$date_from=$request->input('date_from')?$request->input('date_from'):date('Y-m-d',strtotime('- 90 days'));
        $date_to=$request->input('date_to')?$request->input('date_to'):date('Y-m-d');
		
		$datas= DB::connection('order')->table('finances_servicefee_event')->where('PostedDate','>=',$date_from.'T00:00:00Z')->where('PostedDate','<=',$date_to.'T23:59:59Z');
               
        if($request->input('sellerid')){
            $datas = $datas->where('SellerId', $request->input('sellerid'));
        }
		
		if($request->input('bgbu')){
			   $bgbu = $request->input('bgbu');
			   $bgbu_arr = explode('_',$bgbu);
			   if(count($bgbu_arr)>1){
			   	if(array_get($bgbu_arr,0)) $datas = $datas->where('bg',array_get($bgbu_arr,0));
			   	if(array_get($bgbu_arr,1)) $datas = $datas->where('bu',array_get($bgbu_arr,1));
			   }else{
			   		$datas = $datas->whereNull('bg');
			   }
		}
		if($request->input('user_id')){
            $datas = $datas->where('user_id', $request->input('user_id'));
        }
		
		if($request->input('feedes')){
            $datas = $datas->where('Type','like','%'.$request->input('feedes').'%');
        }
		$iTotalRecords = $datas->count();
        $iDisplayLength = intval($_REQUEST['length']);
        $iDisplayLength = $iDisplayLength < 0 ? $iTotalRecords : $iDisplayLength;
        $iDisplayStart = intval($_REQUEST['start']);
        $sEcho = intval($_REQUEST['draw']);
		$lists =  $datas->orderBy($orderby,$sort)->offset($iDisplayStart)->limit($iDisplayLength)->get()->toArray();
        $records = array();
        $records["data"] = array();

        $end = $iDisplayStart + $iDisplayLength;
        $end = $end > $iTotalRecords ? $iTotalRecords : $end;
		$accounts = $this->getSellerId();
		$users= $this->getUsers();
		$lists=json_decode(json_encode($lists), true);
		foreach ( $lists as $list){
            $records["data"][] = array(
                '<label class="mt-checkbox mt-checkbox-single mt-checkbox-outline"><input name="id[]" type="checkbox" class="checkboxes" value="'.$list['Id'].'"/><span></span></label>',
                $list['PostedDate'],
				array_get($accounts,$list['SellerId']),
				$list['Type'],
				$list['bg'].' - '.$list['bu'],
				array_get($users,$list['user_id'],''),
				$list['Amount'].' '.$list['Currency'],
            );
		}
        $records["draw"] = $sEcho;
        $records["recordsTotal"] = $iTotalRecords;
        $records["recordsFiltered"] = $iTotalRecords;
        echo json_encode($records);
    }
	
}