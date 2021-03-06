<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\MultipleQueue;
use PDO;
use DB;
use Illuminate\Http\Response;
class McforderController extends Controller
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
		parent::__construct();
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(!Auth::user()->can(['mcforders'])) die('Permission denied -- mcforders');
		$date_from=date('Y-m-d',strtotime('-90 days'));		
		$date_to=date('Y-m-d');
		//country下拉选择框
		$country= DB::connection('amazon')->select('SELECT DISTINCT country_code FROM amazon_mcf_orders');
		foreach($country as $key=>$val){
			$country[$key] = $val->country_code;
		}
        return view('mcforder/index',['date_from'=>$date_from ,'date_to'=>$date_to,'country'=>$country,'accounts'=>self::getSellerId()]);
    }
	
	
	public function getSellerId(){
		$seller=[];
		$accounts= DB::connection('amazon')->table('seller_accounts')->whereNull('deleted_at')->groupby(['id','label'])->pluck('label','id');
		return $accounts;
	}

    public function show($id)
    {
		if(!Auth::user()->can(['mcforders'])) die('Permission denied -- mcforders');
        $mcf_order = DB::connection('amazon')->table('amazon_mcf_orders')->find($id);
		if($mcf_order){
			$mcf_order->items = DB::connection('amazon')->table('amazon_mcf_orders_item')->where('seller_account_id',$mcf_order->seller_account_id)->where('seller_fulfillment_order_id',$mcf_order->seller_fulfillment_order_id)->get();
			$mcf_order->shipments = DB::connection('amazon')->table('amazon_mcf_shipment_item')->where('seller_account_id',$mcf_order->seller_account_id)->where('seller_fulfillment_order_id',$mcf_order->seller_fulfillment_order_id)->get();
			$mcf_order->packages = DB::connection('amazon')->table('amazon_mcf_shipment_package')->where('seller_account_id',$mcf_order->seller_account_id)->where('seller_fulfillment_order_id',$mcf_order->seller_fulfillment_order_id)->get();
		}else{
			die();
		}
		return view('mcforder/view',['order'=>$mcf_order,'accounts'=>self::getSellerId()]);
    }
    public function get(Request $request)
    {

        if(!Auth::user()->can(['mcforders'])) die('Permission denied -- mcforders');
		$orderby = $request->input('order.0.column',1);
		if($orderby==6){
			$orderby = 'status_updated_date_time';
		}else{
			$orderby = 'displayable_order_date_time';
		}
        $sort = $request->input('order.0.dir','desc');
		
        $date_from=$request->input('date_from')?$request->input('date_from'):date('Y-m-d',strtotime('- 90 days'));
        $date_to=$request->input('date_to')?$request->input('date_to'):date('Y-m-d');
		
		$datas= DB::connection('amazon')->table('amazon_mcf_orders')->where('displayable_order_date_time','>=',$date_from.' 00:00:00')->where('displayable_order_date_time','<=',$date_to.' 23:59:59');
               
        if($request->input('sellerid')){
            $datas = $datas->where('seller_account_id', $request->input('sellerid'));
        }
	
		if($request->input('order_id')){
            $datas = $datas->where('seller_fulfillment_order_id', $request->input('order_id'));
        }
		if($request->input('status')){
            $datas = $datas->where('fulfillment_order_status', $request->input('status'));
        }
		if($request->input('name')){
            $datas = $datas->where('name', $request->input('name'));
        }
		//country下拉选择框
		if($request->input('country')){
			$datas = $datas->where('country_code', $request->input('country'));
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
		$lists=json_decode(json_encode($lists), true);
		foreach ( $lists as $list){
            $records["data"][] = array(
                $list['displayable_order_date_time'],
				array_get($accounts,$list['seller_account_id']),
				$list['seller_fulfillment_order_id'],
				$list['name'],
				$list['country_code'],
				$list['fulfillment_order_status'],
				$list['status_updated_date_time'],
				'<a href="/mcforder/'.$list['id'].'" target="_blank">
					<button type="submit" class="btn btn-success btn-xs">View</button>
				</a>'
            );
		}
        $records["draw"] = $sEcho;
        $records["recordsTotal"] = $iTotalRecords;
        $records["recordsFiltered"] = $iTotalRecords;
        echo json_encode($records);
    }

}