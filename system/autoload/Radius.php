<?php
/**
 * Radius Class
 * based https://gist.github.com/nasirhafeez/6669b24aab0bda545f60f9da5ed14f25
 */
class Radius
{

    public static function getTableNas()
    {
        return ORM::for_table('nas', 'radius');
    }

    public static function getTableCustomer()
    {
        return ORM::for_table('radcheck', 'radius');
    }

    public static function getTablePackage()
    {
        return ORM::for_table('radgroupreply', 'radius');
    }

    public static function getTableUserPackage()
    {
        return ORM::for_table('radusergroup', 'radius');
    }

    public static function nasList($search = null)
    {
        if ($search == null) {
            return ORM::for_table('nas', 'radius')->find_many();
        } else {
            return ORM::for_table('nas', 'radius')
                ->where_like('nasname', $search)
                ->where_like('shortname', $search)
                ->where_like('description', $search)
                ->find_many();
        }
    }

    public static function nasAdd($name, $ip, $ports, $secret, $description = "", $type = 'other', $server = null, $community = null)
    {
        $n = Radius::getTableNas()->create();
        $n->nasname = $ip;
        $n->shortname = $name;
        $n->type = $type;
        $n->ports = $ports;
        $n->secret = $secret;
        $n->description = $description;
        $n->server = $server;
        $n->community = $community;
        $n->save();
        return $n->id();
    }

    public static function nasUpdate($id, $name, $ip, $ports, $secret, $description = "", $type = 'other', $server = null, $community = null)
    {
        $n = Radius::getTableNas()->find_one($id);
        if (empty($n)) {
            return false;
        }
        $n->nasname = $ip;
        $n->shortname = $name;
        $n->type = $type;
        $n->ports = $ports;
        $n->secret = $secret;
        $n->description = $description;
        $n->server = $server;
        $n->community = $community;
        return $n->save();
    }

    public static function planUpSert($plan_id, $rate, $pool = null)
    {
        $rates = explode('/', $rate);
        Radius::upsertPackage($plan_id, 'Ascend-Data-Rate', $rates[1], ':=');
        Radius::upsertPackage($plan_id, 'Ascend-Xmit-Rate', $rates[0], ':=');
        if ($pool != null) {
            Radius::upsertPackage($plan_id, 'Framed-Pool', $pool, ':=');
        }
    }

    public static function planDelete($plan_id)
    {
        // Delete Plan
        Radius::getTablePackage()->where_equal('plan_id', "plan_" . $plan_id)->delete_many();
        // Reset User Plan
        $c = Radius::getTableUserPackage()->where_equal('groupname', "plan_" . $plan_id)->findMany();
        if ($c) {
            foreach ($c as $u) {
                $u->groupname = '';
                $u->save();
            }
        }
    }


    public static function customerChangeUsername($from, $to)
    {
        $c = Radius::getTableCustomer()->where_equal('username', $from)->findMany();
        if ($c) {
            foreach ($c as $u) {
                $u->username = $to;
                $u->save();
            }
        }
        $c = Radius::getTableUserPackage()->where_equal('username', $from)->findMany();
        if ($c) {
            foreach ($c as $u) {
                $u->username = $to;
                $u->save();
            }
        }
    }

    public static function customerDeactivate($username)
    {
        global $radius_pass;
        $r = Radius::getTableCustomer()->where_equal('username', $username)->whereEqual('attribute', 'Cleartext-Password')->findOne();
        if ($r) {
            // no need to delete, because it will make ID got higher
            // we just change the password
            $r->value = md5(time() . $username . $radius_pass);
            $r->save();
        }
    }

    public static function customerDelete($username)
    {
        Radius::getTableCustomer()->where_equal('username', $username)->delete_many();
        Radius::getTableUserPackage()->where_equal('username', $username)->delete_many();
    }

    /**
     * When add a plan to Customer, use this
     */
    public static function customerAddPlan($customer, $plan, $expired = null)
    {
        if (Radius::customerUpsert($customer, $plan)) {
            $p = Radius::getTableUserPackage()->where_equal('username', $customer['username'])->findOne();
            if ($p) {
                // if exists
                $p->groupname = "plan_" . $plan['id'];
                $p->save();
            } else {
                $p = Radius::getTableUserPackage()->create();
                $p->username = $customer['username'];
                $p->groupname = "plan_" . $plan['id'];
                $p->priority = 1;
                $p->save();
            }
            if ($plan['type'] == 'HOTSPOT' && $plan['typebp'] == "Limited") {
                if ($plan['limit_type'] == "Time_Limit") {
                    if ($plan['time_unit'] == 'Hrs')
                        $timelimit = $plan['time_limit'] * 60 * 60;
                    else
                        $timelimit = $plan['time_limit'] * 60;
                    Radius::upsertCustomer($customer['username'], 'Expire-After', $timelimit);
                } else if ($plan['limit_type'] == "Data_Limit") {
                    if ($plan['data_unit'] == 'GB')
                        $datalimit = $plan['data_limit'] . "000000000";
                    else
                        $datalimit = $plan['data_limit'] . "000000";
                        Radius::upsertCustomer($customer['username'], 'Max-Volume', $datalimit);
                } else if ($plan['limit_type'] == "Both_Limit") {
                    if ($plan['time_unit'] == 'Hrs')
                        $timelimit = $plan['time_limit'] * 60 * 60;
                    else
                        $timelimit = $plan['time_limit'] . ":00";
                    if ($plan['data_unit'] == 'GB')
                        $datalimit = $plan['data_limit'] . "000000000";
                    else
                        $datalimit = $plan['data_limit'] . "000000";
                        Radius::upsertCustomer($customer['username'], 'Max-Volume', $datalimit);
                        Radius::upsertCustomer($customer['username'], 'Expire-After', $timelimit);
                }
            }else{
                Radius::getTableCustomer()->where_equal('username', $customer['username'])->whereEqual('attribute', 'Max-Volume')->delete();
                Radius::getTableCustomer()->where_equal('username', $customer['username'])->whereEqual('attribute', 'Expire-After')->delete();
            }
            // expired user
            if($expired!=null){
                Radius::upsertCustomer($customer['username'], 'expiration', date('d M Y H:i:s', strtotime($expired)));
            }else{
                Radius::getTableCustomer()->where_equal('username', $customer['username'])->whereEqual('attribute', 'expiration')->delete();
            }
            return true;
        }
        return false;
    }

    public static function customerUpsert($customer, $plan)
    {
        if ($plan['type'] == 'PPPOE') {
            Radius::upsertCustomer($customer['username'], 'Cleartext-Password', (empty($customer['pppoe_password'])) ? $customer['password'] : $customer['pppoe_password']);
        } else {
            Radius::upsertCustomer($customer['username'], 'Cleartext-Password',  $customer['password']);
        }
        Radius::upsertCustomer($customer['username'], 'Simultaneous-Use', ($plan['type'] == 'PPPOE') ? 1 : $plan['shared_users']);
        return false;
    }

    /**
     * To insert or update existing plan
     */
    private static function upsertPackage($plan_id, $attr, $value, $op = ':=')
    {
        $r = Radius::getTablePackage()->where_equal('plan_id', $plan_id)->whereEqual('attribute', $attr)->find_one();
        if (!$r) {
            $r = Radius::getTablePackage()->create();
            $r->groupname = "plan_" . $plan_id;
            $r->plan_id = $plan_id;
        }
        $r->attribute = $attr;
        $r->op = $op;
        $r->value = $value;
        return $r->save();
    }

    /**
     * To insert or update existing customer
     */
    private static function upsertCustomer($username, $attr, $value, $op = ':=')
    {
        $r = Radius::getTableCustomer()->where_equal('username', $username)->whereEqual('attribute', $attr)->find_one();
        if (!$r) {
            $r = Radius::getTableCustomer()->create();
            $r->username = $username;
        }
        $r->attribute = $attr;
        $r->op = $op;
        $r->value = $value;
        return $r->save();
    }
}
