<?php

namespace Utkarshx\ApiGuard\Models;
use App;
use Eloquent;
use Illuminate\Database\Eloquent\SoftDeletes;
use Utkarshx\ApiGuard\Repositories\ApiKeyRepository;
use DB;
class GroupModel extends ApiKeyRepository
{

    //
    protected $table = 'user_class_map';

    use SoftDeletes;

    protected $dates = ['deleted_at'];

    /**
     * @param $key
     * @return ApiKeyRepository
     */
    public function getByUserIdAndObjectId($userId, $groupId)
    {
        $data = DB::table('user_group_map')
                    ->leftJoin('roles', 'user_group_map.role_id', '=', 'roles.id')
                    ->where('user_group_map.user_id', $userId)
                    ->where('user_group_map.group_id', $groupId)
                    ->first();
        //$data = DB::select(DB::raw('select * from user_group_map where user_id='.$userId.' and group_id='.$groupId));

        if (empty($data)) {
            return null;
        }
        return $data;
    }
}