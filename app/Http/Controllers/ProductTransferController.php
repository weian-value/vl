<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use DB;
use App\Classes\SapRfcRequest;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Auth;
use App\Accounts;
use App\User;


class ProductTransferController extends Controller
{
	use \App\Traits\Mysqli;
	use \App\Traits\DataTables;
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
	 * @return \Illuminate\Http\Responsefba_transfer
	 */
	public function list(Request $req)
	{
		if(!Auth::user()->can(['productTransfer-show'])) die('Permission denied -- productTransfer-show');
		$date = date('Y-m-d');
		if ($req->isMethod('GET')) {
			return view('productTransfer/index', ['date' => $date,'users'=>$this->getUsersIdName(),'bgs'=>$this->getBgs(),'bus'=>$this->getBus()]);
		}
		//搜索相关
		$searchField = array('date'=>'tw.date','asin'=>'tw.asin','bg'=>'asin.bg','bu'=>'asin.bu','sales'=>'users.id','site'=>'tw.site','sellersku'=>'asin.sellersku','item_no'=>'asin.item_no','sku_status'=>'asin.item_status');
		$where = $this->getSearchSql($_POST,$searchField);
		$where .= $this->getAsinWhere('asin.bg','asin.bu','users.id','productTransfer-showall');

		$limit = $this->dtLimit($req);

		$sql = "
        SELECT SQL_CALC_FOUND_ROWS
        	tw.id as id,tw.asin as asin,tw.site as site,tw.sellersku as sellersku,asin.item_no as item_no,asin.seller as seller,asin.bg as bg,asin.bu as bu,asin.status as asin_level, users.name as sales,fs.seller_name as seller_name,asin.item_status as sku_status,'-' as sku_grade, tw.avg_sales as avg_sales,tw.fba_available as fba_available,tw.fba_transfer as fba_transfer,tw.fbmfba_transfer as fbmfba_transfer,tw.fbmfba_sorting as fbmfba_sorting,tw.safety_days as safety_days,tw.fbmfba_days as fbmfba_days,tw.fbmfba_shelfing as fbmfba_shelfing,tw.status as status,fbm_stock.item_name as product_name,tw.fbm_stock as fbm_stock  
            from transfer_warn as tw  
		  	left join asin on asin.sellersku = tw.sellersku and asin.site = tw.site and asin.asin=tw.asin 
        	left join users on users.sap_seller_id = asin.sap_seller_id 
        	left join fba_stock as fs on fs.seller_sku = asin.sellersku and fs.asin = tw.asin 
		  	left join fbm_stock on fbm_stock.item_code = asin.item_no 
			where 1 = 1  {$where} 
			order by avg_sales desc 
        LIMIT {$limit}";
		$data = $this->queryRows($sql);

		//bg,bu,sales,site,seller_name,sellersku,asin,item_no,product_name,sku_status,sku_grade,asin_level,avg_sales,fba_available(fba库存),fba_transfer(fba周转中),fbmfba_transfer(fbm->fba在途中)，fbmfba_sorting(fbm->fba出库中)，safety_days(安全库存天数)，fbmfba_days(fbm_fba的物流周期的天数),fbmfba_shelfing(fbm_fba的物流周期的上架时效),dmi(库存维持天数),remind(建议调拨),allocation_quantity(建议调拨数量),fbm_stock(fbm库存)，tdmi(总库存可维持天数)

		//dmi(库存维持天数),remind(建议调拨),allocation_quantity(建议调拨数量),fbm(fbm库存)，tdmi(总库存可维持天数)
		foreach($data as $key=>$val){
			$data[$key]['sku_status'] = ($val['sku_status'])?'<span class="btn btn-success btn-xs">Reserved</span>':'<span class="btn btn-danger btn-xs">Eliminate</span>';
			//dmi = (fba库存+fba周转中+fbm->fba在途)/日均销量
			//allocation_quantity = （7+安全库存天数+fbm_fba的物流周期的天数+fbm_fba的物流周期的上架时效）* 日均销量-FBA库存-FBA周转中-FBM->FBA在途中
			//tdmi = (FBM库存+FBA库存+FBA周转中+FBM->FBA在途中)/日均销量
			if($val['avg_sales']==0){
				$data[$key]['dmi'] = $data[$key]['tdmi'] = '-';
			}else{
				$data[$key]['dmi'] = round(($val['fba_available']+$val['fba_transfer']+$val['fbmfba_transfer'])/$val['avg_sales'],2);
				$data[$key]['tdmi'] = round(($val['fbm_stock']+$val['fba_available']+$val['fba_transfer']+$val['fbmfba_transfer'])/$val['avg_sales'],2);
			}

			$data[$key]['allocation_quantity'] = round((7+$val['safety_days']+$val['fbmfba_days']+$val['fbmfba_shelfing'])*$val['avg_sales']-$val['fba_available']-$val['fba_transfer']-$val['fbmfba_transfer'],2);
			if($data[$key]['allocation_quantity']>0){
				$data[$key]['remind'] = 'Transfers';
			}elseif($data[$key]['allocation_quantity']>=-50){
				$data[$key]['remind'] = '-';
			}else{
				$data[$key]['remind'] = 'Reduce';
			}
			if($val['status']==2){
				$data[$key]['action'] = 'Ignored';
			}elseif($val['status']==1){
				$data[$key]['action'] = 'Replyed';
			}else{
				if(Auth::user()->can(['productTransfer-reply'])){
					$data[$key]['action'] = '<a href="javacript:void(0);" class="badge reply-process badge-success" data-id="'.$val['id'].'"> Process </a><a href="javacript:void(0);" class="badge reply-ignore badge-danger" data-id="'.$val['id'].'"> Ignore </a>';
				}else{
					$data[$key]['action'] = '-';
				}
			}
		}
		$recordsTotal = $recordsFiltered = $this->queryOne('SELECT FOUND_ROWS()');

		return compact('data', 'recordsTotal', 'recordsFiltered');
	}

	/*
	 * 得到搜索
	 */
	public function getSearchSql($data,$searchField)
	{
		$_POST = $data;
		$search = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
		$search = explode('&',$search);
		$searchData = array();
		foreach($search as $val){
			$sv = explode('=',$val);
			if(isset($sv[1]) && $sv[1]){
				$searchData[$sv[0]] = $sv[1];
				if($sv[0]=='date_to'){
					$searchData[$sv[0]] = $sv[1].' 23:59:59';
				}
			}
		}

		$where = '';
		foreach($searchField as $fk=>$fv){
			if(isset($searchData[$fk]) && $searchData[$fk]){
				if(is_array($fv)){
					foreach($fv as $vk=>$vv){
						$where .= " and {$vv} {$vk} '".$searchData[$fk]."'";
					}
				}else{
					if($fk=='sku_status'){
						$where .= " and {$fv} = '".($searchData[$fk]-1)."'";
					}else{
						$where .= " and {$fv} = '".$searchData[$fk]."'";
					}
				}


			}
		}
		return $where;
	}

	//批量更新天数
	public function updateDays(Request $request)
	{
		if(!Auth::user()->can(['productTransfer-edit'])) die('Permission denied -- productTransfer-edit');
		$ids = isset($_POST['ids']) && $_POST['ids'] ? explode(',',$_POST['ids']) : array();
		$fieldArray = array('safety_days','fbmfba_days','fbmfba_shelfing');
		$update = array();
		foreach($fieldArray as $field){
			if(isset($_POST[$field]) && $_POST[$field]){
				$update[$field] = $_POST[$field];
			}
		}
		$res = 1;
		if($update){
			$update['updated_at'] = date('Y-m-d H:i:s');
			$res = DB::table('transfer_warn')->whereIn('id', $ids)->update($update);
		}
		return $res;
	}

	//申请调拨操作
	public function reply(Request $request)
	{
		if(!Auth::user()->can(['productTransfer-reply'])) die('Permission denied -- productTransfer-reply');
		$id = isset($_POST['id']) && $_POST['id'] ? $_POST['id'] : '';
		$res = 1;
		if($id){
			//先更新transfer_warn表的状态，再插入数据到transfer_reply表中
			$res = DB::table('transfer_warn')->where('id', $id)->update(['status'=>1]);
			if($res){
				$date = date('Y-m-d');
				$sql = "SELECT reply_id from transfer_reply where created_at >= '".$date."' order by created_at desc LIMIT 1";
				$data = $this->queryRows($sql);
				//求需求编号
				if($data && isset($data[0])){
					$insertArray['reply_id'] = $data[0]['reply_id']+1;
				}else{
					$insertArray['reply_id'] = date('Ymd').'1';
				}

				$insertArray['tw_id'] = $id;
				$fields = array('reply_number','reply_reason','reply_label_status','reply_factory','reply_location');
				foreach($fields as $field){
					if(isset($_POST[$field]) && $_POST[$field]){
						$insertArray[$field] = $_POST[$field];
					}
				}
				$insertArray['reply_processor'] = Auth::user()->id;
				$insertArray['created_at'] = date('Y-m-d H:i:s');
				// $insertArray['updated_at'] = date('Y-m-d H:i:s');


				// DB::connection()->enableQueryLog(); // 开启查询日志
				// $queries = DB::getQueryLog(); // 获取查询日志
				if(!DB::table('transfer_reply')->insert($insertArray)){
					$res = 0;
				}
			}
		}else{
			$res = 0;
		}
		return $res;
	}
	//忽略调拨操作
	public function ignore(Request $request)
	{
		if(!Auth::user()->can(['productTransfer-reply'])) die('Permission denied -- productTransfer-reply');
		$id = isset($_POST['id']) && $_POST['id'] ? $_POST['id'] : '';
		$res = 1;
		if($id){
			$res = DB::table('transfer_warn')->where('id', $id)->update(['status'=>2]);
		}
		return $res;
	}
	/*
	 * 调拨申请列表
	 * //VP审核者胡早立id为165
		//计划部审核者 BG1 孙彩凤(294)， BG2 谈际森(298)  BG3 罗雄(299)  BG4 谈际森  (孙彩凤可查看可操作所有的VP已通过的申请)
	 */
	public function replyList(Request $req)
	{
		if(!Auth::user()->can(['productTransfer-reply'])) die('Permission denied -- productTransfer-reply');
		$date_from = $date_to = date('Y-m-d');
		$userId = Auth::user()->id;

		//看是否是bg,bu的管理者，是管理者的话，BG,BU可进行process操作，且BG的话要限制显示的状态为BU已批准的状态（>0）
		$bgManage = $buManage = '';
		if (Auth::user()->seller_rules) {
			$rules = explode("-",Auth::user()->seller_rules);
			if(array_get($rules,0)!='*') $bgManage = array_get($rules,0);
			if(array_get($rules,1)!='*') $buManage = array_get($rules,1);
		}
		//各个状态显示键值对
		$statusArr = array(0=>'-',1=>'Rejected By BU',2=>'Approved By BU',3=>'Rejected By BG',4=>'Approved By BG',5=>'Rejected By VP',6=>'Approved By VP',7=>'Rejected By plan',8=>'Approved By plan');
		$planUserId = array(294,298,299);
		$dataApproveType = $dataRejectType = 0;
		if($bgManage){
			if($buManage){
				//bu管理者
				$dataApproveType = 2;
				$dataRejectType = 1;
			}else{
				//BG管理者
				$dataApproveType = 4;
				$dataRejectType = 3;
			}
		}
		if($userId==165){//VP操作者
			$dataApproveType = 6;
			$dataRejectType = 5;
		}
		if(in_array($userId,$planUserId)){//计划部操作者
			$dataApproveType = 8;
			$dataRejectType = 7;
		}
		$dataApproContent = $dataApproveType ? $statusArr[$dataApproveType] : '';
		$dataRejectContent = $dataRejectType ? $statusArr[$dataRejectType] : '';

		if ($req->isMethod('GET')) {
			return view('productTransfer/replyList', ['date_from' => $date_from,'date_to' => $date_to,'users'=>$this->getUsersIdName(),'bgs'=>$this->getBgs(),'bus'=>$this->getBus(),'dataApproveType'=>$dataApproveType,'dataRejectType'=>$dataRejectType,'dataApproContent'=>$dataApproContent,'dataRejectContent'=>$dataRejectContent]);
		}
		//搜索相关
		$searchField = array('date_from'=>array('>='=>'tr.created_at'),'date_to'=>array('<='=>'tr.created_at'),'asin'=>'tw.asin','bg'=>'asin.bg','bu'=>'asin.bu','sales'=>'users.id','site'=>'tw.site','sellersku'=>'asin.sellersku','item_no'=>'asin.item_no','sku_status'=>'asin.item_status');
		$where = $this->getSearchSql($_POST,$searchField);


		//reply_status， '申请状态，0默认，1BU拒绝，2BU批准，3BG拒绝，4BG批准，5VP拒绝，6VP批准，7计划部拒绝，8计划部批准',
		if($bgManage && empty($buManage)){
			$where .= ' and reply_status >= 2';
		}
		if($userId==165){//VP操作者
			$where .= ' and reply_status >= 4';
		}
		if(in_array($userId,$planUserId)){//计划部操作者
			$where .= ' and reply_status >= 6';
			//BG1 孙彩凤(294)， BG2 谈际森(298)  BG3 罗雄(299)  BG4 谈际森
			if($userId==298){
				$where .= " and asin.bg in ('BG2','BG4')";
			}
			if($userId==299){
				$where .= " and asin.bg in ('BG3')";
			}

		}else{
			$where .= $this->getAsinWhere('asin.bg','asin.bu','users.id','');
		}


		$limit = $this->dtLimit($req);
		$sql = "
        SELECT SQL_CALC_FOUND_ROWS
        	tr.reply_id as id,tr.created_at as created_at,tr.reply_status as reply_status,asin.bg as bg,asin.bu as bu,users.name as sales,tw.asin as asin,tw.site as site,tw.sellersku as sellersku,fs.seller_name as seller_name,asin.item_no as item_no,asin.item_status as sku_status,asin.status as asin_level, '-' as sku_grade,tr.reply_number as reply_number,tr.reply_reason as reply_reason,tr.audit_date as audit_date,tw.safety_days as safety_days,tw.fbmfba_days as fbmfba_days,tw.fbmfba_shelfing as fbmfba_shelfing,tw.avg_sales as avg_sales, tw.fba_available as fba_available,tw.fba_transfer as fba_transfer,tw.fbmfba_transfer as fbmfba_transfer,tr.opera_log as opera_log   
        	from transfer_reply as tr 
            left join transfer_warn as tw on tr.tw_id = tw.id 
		  	left join asin on asin.sellersku = tw.sellersku and asin.site = tw.site and asin.asin=tw.asin 
        	left join users on users.sap_seller_id = asin.sap_seller_id 
        	left join fba_stock as fs on fs.seller_sku = asin.sellersku and fs.asin = tw.asin 
		  	left join kms_stock as ks on ks.seller_sku = asin.sellersku and ks.asin = tw.asin 
			where 1=1 {$where}
			order by tr.created_at desc 
        LIMIT {$limit}";

		$data = $this->queryRows($sql);

		foreach($data as $key=>$val){
			$data[$key]['process'] = '<div class="process" data-id="'.$val['id'].'">'.(isset($statusArr[$val['reply_status']]) && $statusArr[$val['reply_status']] ? $statusArr[$val['reply_status']] : $val['reply_status']) .'</div>';
			//当状态为1（BU已批准）且是bg管理者登录的时候可进行批准拒绝操作
			$process = '<div class="process" data-id="'.$val['id'].'">
<a href="javacript:void(0);" class="badge reply-audit badge-success process-one" data-type="'.$dataApproveType.'" data-content="'.$dataApproContent.'" data-audit="1"> Approve </a>
<a href="javacript:void(0);" class="badge reply-audit badge-danger process-one" data-type="'.$dataRejectType.'" data-content="'.$dataRejectContent.'" data-audit="2"> Reject </a>
</div>';
			if($bgManage){
				if($buManage){
					//bu管理者
					if($val['reply_status']==0){//可操作的状态
						$data[$key]['process'] = $process;
					}
				}elseif($val['reply_status']==2){//BG管理者并且可操作的状态
					$data[$key]['process'] = $process;
				}
			}
			if($userId==165 && $val['reply_status']==4){//VP操作者并且是可操作的状态
				$data[$key]['process'] = $process;
			}

			if(in_array($userId,$planUserId)){//计划部操作者
				if($val['reply_status']==6) {//可操作的状态
					$data[$key]['process'] = $process;//计划部操作者可操作
					//计划部操作者登录的时候，这里才可以改数量
					$data[$key]['reply_number'] = '<input style="width:50px;" type="text" class="reply-number" name="reply_number" data-id="'.$val['id'].'" value="'.$val['reply_number'].'">';
				}
			}

			$data[$key]['sku_status'] = ($val['sku_status'])?'<span class="btn btn-success btn-xs">Reserved</span>':'<span class="btn btn-danger btn-xs">Eliminate</span>';
			$data[$key]['suggest_num'] = round((7+$val['safety_days']+$val['fbmfba_days']+$val['fbmfba_shelfing'])*$val['avg_sales']-$val['fba_available']-$val['fba_transfer']-$val['fbmfba_transfer'],2);

			$data[$key]['opera_log'] = '<a href="javacript:void(0);" class="badge log-detail badge-success"  data-id="'.$val['id'].'"> Log details </a>';
			if(!$val['audit_date']){
				$data[$key]['audit_date'] = '-';
			}
		}
		$recordsTotal = $recordsFiltered = $this->queryOne('SELECT FOUND_ROWS()');
		return compact('data', 'recordsTotal', 'recordsFiltered');
	}

	//修改申请调拨内容，例如修改调拨数量
	public function updateReply(Request $req)
	{
		$id = isset($_POST['id']) && $_POST['id'] ? $_POST['id'] : 0;
		$replyNumber = isset($_POST['reply_number']) && $_POST['reply_number'] ? $_POST['reply_number'] : 0;
		$res = 1;
		$users = $this->getUsersIdName();
		$userid = Auth::user()->id;
		$userName = isset($users[$userid]) && $users[$userid] ? $users[$userid] : '';

		//type等于0的时候为更改申请调拨数量的操作
		$sql = "SELECT opera_log,reply_number from transfer_reply where reply_id = {$id} LIMIT 1";
		$data = $this->queryRows($sql);
		if($id && $data && isset($data[0])){
			$operaLog = $data[0]['opera_log'];
			$operaLog .= 'Change the number of reply from '.$data[0]['reply_number'].' to '.$replyNumber.' at  ' . date('H:i:s,Y-m-d') . ' by '.$userName.'<br>';
			$res = DB::table('transfer_reply')->where('reply_id', $id)->update(['reply_number'=>$replyNumber,'opera_log'=>$operaLog]);
		}else{
			$res = 0;
		}
		return $res;
	}
	/*
	 * 审核请求的批准拒绝操作
	 */
	public function replyAudit(Request $req)
	{
		$idstr = isset($_POST['id']) && $_POST['id'] ? $_POST['id'] : '';
		$type = isset($_POST['type']) && $_POST['type'] ? $_POST['type'] : '';

		$res = 0;
		$sql = "SELECT reply_id as id,opera_log from transfer_reply where reply_id in({$idstr})";
		$data = $this->queryRows($sql);
		if($data){
			$users = $this->getUsersIdName();
			$userid = Auth::user()->id;
			$userName = isset($users[$userid]) && $users[$userid] ? $users[$userid] : '';

			$statusArr = array(0=>'-',1=>'Rejected',2=>'Approve',3=>'Rejected',4=>'Approve',5=>'Rejected',6=>'Approve',7=>'Rejected',8=>'Approve');
			$staval = isset($statusArr[$type]) ? $statusArr[$type] : '-';
			foreach($data as $key=>$val){
				$operaLog = $val['opera_log'];
				$operaLog .= $staval.' at  ' . date('H:i:s,Y-m-d') . ' by '.$userName.'<br>';
				$res = DB::table('transfer_reply')->where('reply_id', $val['id'])->update(['reply_status'=>$type,'opera_log'=>$operaLog,'audit_date'=>date('Y-m-d H:i:s')]);
			}
		}else{
			$res = 0;
		}
		return $res;

	}

	//点击显示操作日志
	public function showLog()
	{
		$id = isset($_POST['id']) && $_POST['id'] ? $_POST['id'] : 0;
		$sql = "SELECT opera_log from transfer_reply where reply_id = {$id} LIMIT 1";
		$data = $this->queryRows($sql);
		if($id && $data && isset($data[0])) {
			if(!$data[0]['opera_log']){
				$data[0]['opera_log'] = '暂无操作日志';
			}
			return json_encode($data[0]);
		}else{
			return 0;
		}
	}


}