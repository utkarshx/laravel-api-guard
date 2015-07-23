<?php

namespace Utkarshx\ApiGuard\Models;
use App;
use Eloquent;
use Illuminate\Database\Eloquent\SoftDeletes;
use Utkarshx\ApiGuard\Repositories\ApiKeyRepository;
use DB;
class ClassModel extends ApiKeyRepository
{

    //
    protected $table = 'user_class_map';

    use SoftDeletes;

    protected $dates = ['deleted_at'];

    /**
     * @param $key
     * @return ApiKeyRepository
     */
    public function getByUserIdAndObjectId($userId, $classId)
    {
        //$data = DB::select(DB::raw('select * from user_class_map where user_id='.$userId.' and class_id='.$classId));
        $data = DB::table('user_class_map')
            ->leftJoin('roles', 'user_class_map.role_id', '=', 'roles.id')
            ->where('user_id', $userId)
            ->where('class_id', $classId)
            ->first();

        if (empty($data)) {
            return null;
        }


        return $data;
    }
}