<?php

namespace Refaltor\CustomItemAPI\Events\Listeners;

use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\BlockToolType;
use pocketmine\block\ItemFrame;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\Position;
use Refaltor\CustomItemAPI\CustomItemMain;
use Refaltor\CustomItemAPI\Items\AxeItem;
use Refaltor\CustomItemAPI\Items\HoeItem;
use Refaltor\CustomItemAPI\Items\PickaxeItem;
use Refaltor\CustomItemAPI\Items\ShovelItem;
use Refaltor\CustomItemAPI\Items\SwordItem;

class PacketListener implements Listener
{
    private CustomItemMain $plugin;

    public function __construct(CustomItemMain $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onDataPacketSend(DataPacketSendEvent $event) : void{
        $packets = $event->getPackets();
        foreach($packets as $packet){
            if($packet instanceof StartGamePacket){
                $packet->levelSettings->experiments = new Experiments([
                    "data_driven_items" => true
                ], true);
            }elseif($packet instanceof ResourcePackStackPacket){
                $packet->experiments = new Experiments([
                    "data_driven_items" => true
                ], true);
            }
        }
    }

    protected array $handlers = [];

    /**
     * @param DataPacketReceiveEvent $event
     *
     * @priority HIGHEST
     */
    public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
        $packet = $event->getPacket();
        if(!$packet instanceof PlayerActionPacket){
            return;
        }
        $handled = false;
        try{
            $pos = new Vector3($packet->blockPosition->getX(), $packet->blockPosition->getY(), $packet->blockPosition->getZ());
            $player = $event->getOrigin()?->getPlayer() ?: throw new AssumptionFailedError("This packet cannot be received from non-logged in player");
            if($packet->action === PlayerAction::START_BREAK){
                $item = $player->getInventory()->getItemInHand();

                if (!$item instanceof PickaxeItem && !$item instanceof AxeItem && !$item instanceof ShovelItem && !$item instanceof SwordItem && !$item instanceof HoeItem) {
                    return;
                }

                if($pos->distanceSquared($player->getPosition()) > 10000){
                    return;
                }

                $target = $player->getWorld()->getBlock($pos);

                $ev = new PlayerInteractEvent($player, $player->getInventory()->getItemInHand(), $target, null, $packet->face, PlayerInteractEvent::LEFT_CLICK_BLOCK);
                if($player->isSpectator()){
                    $ev->cancel();
                }

                $ev->call();
                if($ev->isCancelled()){
                    $event->getOrigin()->getInvManager()?->syncSlot($player->getInventory(), $player->getInventory()->getHeldItemIndex());
                    return;
                }

                $frameBlock = $player->getWorld()->getBlock($pos);
                if($frameBlock instanceof ItemFrame && $frameBlock->getFramedItem() !== null){
                    if(lcg_value() <= $frameBlock->getItemDropChance()){
                        $player->getWorld()->dropItem($frameBlock->getPosition(), $frameBlock->getFramedItem());
                    }
                    $frameBlock->setFramedItem(null);
                    $frameBlock->setItemRotation(0);
                    $player->getWorld()->setBlock($pos, $frameBlock);
                    return;
                }
                $block = $target->getSide($packet->face);
                if($block->getId() === BlockLegacyIds::FIRE){
                    $player->getWorld()->setBlock($block->getPosition(), BlockFactory::getInstance()->get(BlockLegacyIds::AIR, 0));
                    return;
                }

                $pass = false;
                if ($item instanceof PickaxeItem && $target->getBreakInfo()->getToolType() === BlockToolType::PICKAXE) $pass = true;
                if ($item instanceof HoeItem && $target->getBreakInfo()->getToolType() === BlockToolType::HOE) $pass = true;
                if ($item instanceof AxeItem && $target->getBreakInfo()->getToolType() === BlockToolType::AXE) $pass = true;
                if ($item instanceof ShovelItem && $target->getBreakInfo()->getToolType() === BlockToolType::SHOVEL) $pass = true;
                if ($item instanceof SwordItem && $target->getBreakInfo()->getToolType() === BlockToolType::SWORD) $pass = true;

                if ($pass) {
                    if (!$player->isCreative()) {
                        $handled = true;
                        $breakTime = ceil($target->getBreakInfo()->getBreakTime($player->getInventory()->getItemInHand()) * 20);
                        if ($breakTime > 0) {
                            if ($breakTime > 10) {
                                $breakTime -= 10;
                            }
                            $item->onDestroyBlock($target);
                            $this->scheduleTask(Position::fromObject($pos, $player->getWorld()), $player->getInventory()->getItemInHand(), $player, $breakTime);
                            $player->getWorld()->broadcastPacketToViewers($pos, LevelEventPacket::create(LevelEvent::BLOCK_START_BREAK, (int)(65535 / $breakTime), $pos->asVector3()));
                        }
                    }
                }
            }elseif($packet->action === PlayerAction::ABORT_BREAK){
                $player->getWorld()->broadcastPacketToViewers($pos, LevelEventPacket::create(LevelEvent::BLOCK_STOP_BREAK, 0, $pos->asVector3()));
                $handled = true;
                $this->stopTask($player, Position::fromObject($pos, $player->getWorld()));
            }
        }finally{
            if($handled){
                $event->cancel();
            }
        }
    }



    public function onPlayerQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        if(!isset($this->handlers[$player->getName()])){
            return;
        }
        foreach($this->handlers[$player->getName()] as $blockHash => $handler){
            $handler->cancel();
        }
        unset($this->handlers[$player->getName()]);
    }

    private function scheduleTask(Position $pos, Item $item, Player $player, float $breakTime) : void{
        $handler = $this->getPlugin()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($pos, $item, $player) : void{
            $pos->getWorld()->useBreakOn($pos, $item, $player);
            $item->applyDamage(1);
            unset($this->handlers[$player->getName()][$this->blockHash($pos)]);
        }), (int) floor($breakTime));
        if(!isset($this->handlers[$player->getName()])){
            $this->handlers[$player->getName()] = [];
        }
        $this->handlers[$player->getName()][$this->blockHash($pos)] = $handler;
    }

    private function stopTask(Player $player, Position $pos) : void{
        if(!isset($this->handlers[$player->getName()][$this->blockHash($pos)])){
            return;
        }
        $handler = $this->handlers[$player->getName()][$this->blockHash($pos)];
        $handler->cancel();
        unset($this->handlers[$player->getName()][$this->blockHash($pos)]);
    }

    private function blockHash(Position $pos) : string{
        return implode(":", [$pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $pos->getWorld()->getFolderName()]);
    }


    public function getPlugin(): CustomItemMain
    {
        return $this->plugin;
    }
}