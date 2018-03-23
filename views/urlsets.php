<?php
/**
 * @author Semenov Alexander <semenov@skeeks.com>
 * @link http://skeeks.com/
 * @copyright 2010 SkeekS (СкикС)
 * @date 08.11.2016
 */

?>
<offers>
<? foreach($data as $data_elem) :
    foreach ($data_elem as $key=>$item) :
        if ($key=='offer') : ?><offer id="<?=$item['id'];?>" available="<?=$item['available'];?>">
        <? else : ?><<?=$key;?>><?= $item; ?></<?=$key;?>><? endif; ?>
    <? endforeach; ?>
    </offer>
<? endforeach; ?>
</offers>

