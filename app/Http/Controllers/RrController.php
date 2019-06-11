<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\MultipleQueue;
use DB;
class RrController extends Controller
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
        if(!Auth::user()->can(['requestreport-show'])) die('Permission denied -- requestreport-show');
        $datas= DB::connection('order')->table('request_report')->orderBy('RequestDate','Desc')->get()->toArray();
        return view('rr/index',['datas'=>$datas,'users'=>$this->getUsers(),'accounts'=>$this->getAccounts()]);

    }

    public function getUsers(){
        $users = User::get()->toArray();
        $users_array = array();
        foreach($users as $user){
            $users_array[$user['id']] = $user['name'];
        }
        return $users_array;
    }
	
	


     public function getAccounts(){
		
		$seller=[];
		$accounts= DB::connection('order')->table('accounts')->where('status',1)->groupBy(['sellername','sellerid'])->get(['sellername','sellerid']);
		$accounts=json_decode(json_encode($accounts), true);
		foreach($accounts as $account){
			$seller[$account['sellerid']]=$account['sellername'];
		}
		return $seller;
		
    }

    public function create()
    {
        if(!Auth::user()->can(['requestreport-create'])) die('Permission denied -- requestreport-create');
        return view('rr/add',['users'=>$this->getUsers(),'accounts'=>$this->getAccounts()]);
    }


    public function store(Request $request)
    {
        if(!Auth::user()->can(['requestreport-create'])) die('Permission denied -- requestreport-create');
        $this->validate($request, [
            'sellerid' => 'required|array',
            'type' => 'required|string',
        ]);
		$insertData=[];
		foreach($request->get('sellerid') as $sellerid){
		$insertData[] = array('SellerId'=>$sellerid,
			'Type'=>$request->get('type'),
			'UserId'=>Auth::user()->id,
			'Message'=>'_IN_PROGRESS_',
			'RequestDate'=>date('Y-m-d H:i:s')
			);
		}
        $result = DB::connection('order')->table('request_report')->insert($insertData);
        if ($result) {
            $request->session()->flash('success_message','Set Report Success');
            return redirect('rr');
        } else {
            $request->session()->flash('error_message','Set Report Failed');
            return redirect()->back()->withInput();
        }
    }


    public function destroy(Request $request,$id)
    {
        if(!Auth::user()->can(['requestreport-delete'])) die('Permission denied -- requestreport-delete');
        $result = DB::connection('order')->table('request_report')->where('id',$id)->delete();
        $request->session()->flash('success_message','Delete Report Success');
        return redirect('rr');
    }

    public function edit(Request $request,$id)
    {
        if(!Auth::user()->can(['requestreport-download'])) die('Permission denied -- requestreport-download');
        $result = DB::connection('order')->table('request_report')->where('id',$id)->first()->toArray();
        if($result){
            print_r($result);
        }
    }
}