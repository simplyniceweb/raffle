<?php

namespace admin;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use general\Tools;

class Winners
{
    public $active = 3;
    
    public function winnersOverview(Request $req, Application $app)
    {
       $raffles = Tools::findBy($app, '\Raffles', array('view_status' => 5, 'raffle_status' => 0), array('created_at' => 'DESC'));
       $prizes = Tools::findBy($app, '\Prizes', array('view_status' => 5), array('created_at' => 'DESC'));
        
        $view = array(
            'title' => 'Winners',
            'raffles' => $raffles,
            'prizes' => $prizes,
            'isprizes' => self::isPrizeAdded($req, $app, $prizes, (!empty($raffles))?$raffles{0}:null, 1),
            'active_tab' => $this->active,
			'message' => $app['session']->getFlashBag()->get('message'),
        );
        
		return $app['twig']->render('dashboard/winners.twig', $view);
    }
    
    public function winnerPrizes(Request $req, Application $app)
    {
        $raffle = (int) $req->get('raffle');
        $winner = (int) $req->get('winner');
        
        $prizes_ = Tools::findBy($app, '\Prizes', array('view_status' => 5), array('created_at' => 'DESC'));
        $raffle_ = Tools::findOneBy($app, '\Raffles', array('id' => $raffle, 'view_status' => 5));
        
        $view = array(
            'prizes' => $prizes_,
            'isprizes' => self::isPrizeAdded($req, $app, $prizes_, $raffle_, $winner)
        );
        
        return $app['twig']->render('dashboard/includes/list.winner.prizes.twig', $view);
    }
    
    public function isPrizeAdded(Request $req, Application $app, $prizes, $raffle = NULL, $winner = 1)
    {
        $isPrize = array();
        
        foreach ($prizes as $prize)
        {
            $qb = $app['orm.em']->createQueryBuilder();
            $qb->select('count(table)')
            ->from('models\Winners', 'table')
            ->where('table.raffle = :raffle')
            ->andWhere('table.prize = :prize')
            ->andWhere('table.winner = :winner')
            ->andWhere('table.view_status = 5')
            ->setParameter('raffle', $raffle)
            ->setParameter('prize', $prize)
            ->setParameter('winner', $winner);
    
            $count = $qb->getQuery()->getSingleScalarResult();
            $isPrize[] = ($count == 0) ? 0 : 1;
        }
        
        return $isPrize;
    }
    
    public function addPrize(Request $req, Application $app)
    {
        $prize = (int) $req->get('prize');
        $raffle = (int) $req->get('raffle');
        $winner = (int) $req->get('winner');
        $status = (int) $req->get('status');
        
        $prize_ = Tools::findOneBy($app, '\Prizes', array('id' => $prize, 'view_status' => 5));
        $raffle_ = Tools::findOneBy($app, '\Raffles', array('id' => $raffle, 'view_status' => 5));

        $winner_ = Tools::findOneBy($app, '\Winners', array('raffle' => $raffle, 'prize' => $prize, 'winner' => $winner));
        if (empty($winner_))
        {
            $winner_ = new \models\Winners;
        }

        $winner_->setWinner($winner);
        $winner_->setRaffle($raffle_);
        $winner_->setPrize($prize_);
        $winner_->setViewStatus($status);
        $winner_->setModifiedAt('now');
        $winner_->setCreatedAt('now');
        
		$app['orm.em']->persist($winner_);
		$app['orm.em']->flush();
        
        return json_encode(array($prize,$raffle,$winner,$status));
    }
}