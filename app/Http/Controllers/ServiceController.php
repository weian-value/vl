<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TrackLog;
use App\Models\Ctg;
use App\Sendbox;
use App\Task;
use App\RsgRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PDO;
use DB;
use log;
use App\Classes\SapRfcRequest;
use App\Accounts;
use App\Category;

class ServiceController extends Controller
{
    use \App\Traits\Mysqli;

    /**
     * Create a new controller instance.
     *
     * @return void
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
    public function index(Request $request)
    {
		$date_from = $request->get('date_from')?$request->get('date_from'):(date('Y-m').'-01');
		$date_to = $request->get('date_to')?$request->get('date_to'):date('Y-m-d');
		if($date_from>$date_to) $date_from=$date_to;
		$today = date('Y-m-d');
		$yesterday = date('Y-m-d',strtotime('-1day'));
		$users = array_get(getUserGroupDetails(),'users',[]);
		$user_id = Auth::user()->id;
		if(array_get($_REQUEST,'user_id') && array_key_exists(array_get($_REQUEST,'user_id'),$users)){
			$user_id = intval(array_get($_REQUEST,'user_id'));
		}
		//$user_id=77;
		$details = $dash = [];
		$total_score = 0;
		$channel_score = ['0'=>2,'1'=>3,'2'=>3,'3'=>1,'sg'=>5,'rsg'=>20];
		$emails = Sendbox::select(DB::Raw('count(*) as count,date(send_date) as sdate'))->where('user_id',$user_id)->where('send_date','>=',$date_from.' 00:00:00')->where('send_date','<=',$date_to.' 23:59:59')->groupBy('sdate')->pluck('count','sdate')->toArray();
		foreach($emails as $k=>$v){
			$details[$k][3] = $v*array_get($channel_score,'3',0);
			$total_score+=$details[$k][3];
		}
		
		unset($emails);
		
		$others = TrackLog::select(DB::Raw('count(*) as count,date(created_at) as sdate,channel'))->where('processor',$user_id)->whereIn('channel',['0','1','2'])->where('type',2)->where('created_at','>=',$date_from.' 00:00:00')->where('created_at','<=',$date_to.' 23:59:59')->groupBy(['sdate','channel'])->get()->toArray();
		$score = 0;
		foreach($others as $val){
			$details[$val['sdate']][$val['channel']] = $val['count']*array_get($channel_score,$val['channel'],0);
			$total_score+=$details[$val['sdate']][$val['channel']];
		}
		unset($others);

		$sg = Ctg::select(DB::Raw('date(created_at) as sdate,count(*) as count'))->where('processor',$user_id)->where('commented',1)->where('created_at','>=',$date_from.' 00:00:00')->where('created_at','<=',$date_to.' 23:59:59')->groupBy(['sdate'])->pluck('count','sdate')->toArray();
		foreach($sg as $k=>$v){
			$details[$k]['sg'] = $v*array_get($channel_score,'sg',0);
			$total_score+=$details[$k]['sg'];
		}
		unset($sg);
		
		$rsg = RsgRequest::select(DB::Raw('date(updated_at) as sdate,count(*) as count'))->where('processor',$user_id)->where('step',9)->where('updated_at','>=',$date_from.' 00:00:00')->where('updated_at','<=',$date_to.' 23:59:59')->groupBy(['sdate'])->pluck('count','sdate')->toArray();
		foreach($rsg as $k=>$v){
			$details[$k]['rsg'] = $v*array_get($channel_score,'rsg',0);
			$total_score+=$details[$k]['rsg'];
		}
		unset($rsg);
		
		
		if(!array_key_exists($today,$details)){
			$dash[0][0]= intval(Sendbox::select(DB::Raw('count(*) as count'))->where('user_id',$user_id)->where('send_date','>=',$today.' 00:00:00')->where('send_date','<=',$today.' 23:59:59')->value('count'))+intval(TrackLog::select(DB::Raw('count(*) as count'))->where('processor',$user_id)->whereIn('channel',['0','1','2'])->where('type',2)->where('created_at','>=',$today.' 00:00:00')->where('created_at','<=',$today.' 23:59:59')->value('count'));
			
			$dash[2][0]= intval(Ctg::select(DB::Raw('count(*) as count'))->where('processor',$user_id)->where('commented',1)->where('created_at','>=',$today.' 00:00:00')->where('created_at','<=',$today.' 23:59:59')->value('count'));
			$dash[3][0]= intval(RsgRequest::select(DB::Raw('count(*) as count'))->where('processor',$user_id)->where('step',9)->where('updated_at','>=',$today.' 00:00:00')->where('updated_at','<=',$today.' 23:59:59')->value('count'));	
		}else{
			$dash[0][0]=$dash[2][0]=$dash[3][0]=0;
			foreach(array_get($details,$today) as $k=>$v){
				$dash[0][0]+=intval($v/array_get($channel_score,$k));
				if($k=='sg') $dash[2][0]+=intval($v/array_get($channel_score,$k));
				if($k=='sg') $dash[3][0]+=intval($v/array_get($channel_score,$k));
			}
			
		}
		if(!array_key_exists($yesterday,$details)){
			$dash[0][1]= intval(Sendbox::select(DB::Raw('count(*) as count'))->where('user_id',$user_id)->where('send_date','>=',$yesterday.' 00:00:00')->where('send_date','<=',$yesterday.' 23:59:59')->value('count'))+intval(TrackLog::select(DB::Raw('count(*) as count'))->where('processor',$user_id)->whereIn('channel',['0','1','2'])->where('type',2)->where('created_at','>=',$yesterday.' 00:00:00')->where('created_at','<=',$yesterday.' 23:59:59')->value('count'));
			
			$dash[2][1]= intval(Ctg::select(DB::Raw('count(*) as count'))->where('processor',$user_id)->where('commented',1)->where('created_at','>=',$yesterday.' 00:00:00')->where('created_at','<=',$yesterday.' 23:59:59')->value('count'));
			$dash[3][1]= intval(RsgRequest::select(DB::Raw('count(*) as count'))->where('processor',$user_id)->where('step',9)->where('updated_at','>=',$yesterday.' 00:00:00')->where('updated_at','<=',$yesterday.' 23:59:59')->value('count'));	
		}else{
			$dash[0][1]=$dash[2][1]=$dash[3][1]=0;
			foreach(array_get($details,$yesterday) as $k=>$v){
				$dash[0][1]+=intval($v/array_get($channel_score,$k));
				if($k=='sg') $dash[2][1]+=intval($v/array_get($channel_score,$k));
				if($k=='sg') $dash[3][1]+=intval($v/array_get($channel_score,$k));
			}
			
		}

		//得到需要统计的数量
		$statis = $this->getNoreplyData();
		$statis = $statis + $this->getRRData();
		
		$tasks = Task::where('response_user_id',Auth::user()->id)->where('stage','<',3)->take(10)->orderBy('priority','desc')->get()->toArray();
        return view('service',compact('total_score','tasks','dash','details','users','date_from','date_to','user_id','statis'));

    }

    public function fastSearch(Request $req){
        $searchType = $req->input('searchType', 0);
        $searchTerm = trim($req->input('searchTerm', ''));

        if($searchTerm != '') {
            //Order ID
            if ($searchType == 0) {
                $sap = new SapRfcRequest();
                try{
                    $order = SapRfcRequest::sapOrderDataTranslate($sap->getOrder(['orderId' => $searchTerm]));
                    $order['SellerName'] = Accounts::where('account_sellerid', $order['SellerId'])->first()->account_name ?? 'No Match';
                }catch(\Exception $e){
                    return 'No matching order...';
                }
                return view('service.orderinfo', ['order' => $order]);

            }
            //Customer Info
            else if ($searchType == 1) {
                $id = $searchTerm;
                $sap = new SapRfcRequest();
                $sql = "select b.name as name,b.email as email,b.phone as phone,b.remark as remark,b.country as country,b.`from` as `from`,c.amazon_order_id as order_id
			FROM client_info as b
			left join client_order_info as c on b.id = c.ci_id
			where b.client_id = $id
			order by b.id desc ";

                try{
                    $_data = $this->queryRows($sql);
                }catch (\Exception $e) {
                    return 'No matching customer...';
                }

                if(empty($_data)){
                    return 'No matching customer...';
                }

                $orderArr = array();
                $contactInfo = array();
                $emails = array();
                foreach ($_data as $key => $val) {
                    //获取sap订单信息
                    $orderid = $val['order_id'];
                    try {
                        $orderArr[$key] = SapRfcRequest::sapOrderDataTranslate($sap->getOrder(['orderId' => $orderid]));
                        $orderArr[$key]['SellerName'] = Accounts::where('account_sellerid', $orderArr[$key]['SellerId'])->first()->account_name ?? 'No Match';
                    } catch (\Exception $e) {

                    }
                    //联系人基本信息
                    $contactInfo[$val['email']] = array('name' => $val['name'], 'email' => $val['email'], 'phone' => $val['phone'], 'remark' => $val['remark'], 'country' => $val['country']);
                    $emails[] = $val['email'];
                }

                $userRows = DB::table('users')->select('id', 'name')->get();

                $users = array();
                foreach ($userRows as $row) {
                    $users[$row->id] = $row->name;
                }
                //与联系人的邮箱联系内容
                $emails = DB::table('sendbox')->whereIn('to_address', $emails)->orderBy('date', 'desc')->get();
                $emails = json_decode(json_encode($emails), true); // todo

                $track_log_array = TrackLog::where('record_id', $id)->where('type', 2)->orderBy('created_at', 'desc')->get()->toArray();
                $subject_type = $this->getSubjectType();

                return view('service/customerinfo', ['orderArr' => $orderArr, 'contactInfo' => $contactInfo, 'emails' => $emails, 'users' => $users, 'track_log_array' => $track_log_array, 'subject_type' => $subject_type, 'record_id' => $id]);

            }
        }

    }

    public function getSubjectType(){
        return Category::where('category_pid',28)->orderBy('created_at','desc')->pluck('category_name','id');
    }


}