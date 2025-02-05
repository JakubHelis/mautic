<?php

namespace Mautic\CampaignBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\CalendarBundle\CalendarEvents;
use Mautic\CalendarBundle\Event\CalendarGeneratorEvent;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CalendarSubscriber implements EventSubscriberInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var RouterInterface
     */
    private $router;

    public function __construct(Connection $connection, TranslatorInterface $translator, RouterInterface $router)
    {
        $this->connection = $connection;
        $this->translator = $translator;
        $this->router     = $router;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CalendarEvents::CALENDAR_ON_GENERATE => ['onCalendarGenerate', 0],
        ];
    }

    /**
     * Adds events to the calendar.
     */
    public function onCalendarGenerate(CalendarGeneratorEvent $event)
    {
        $dates = $event->getDates();
        $now   = new DateTimeHelper();

        $commonSelect            = 'cl.campaign_id, c.name AS campaign_name, l.firstname, l.lastname, ce.type AS event_type, ce.name as event_name, cat.color';
        $eventTypes              = [];
        $eventTypes['triggered'] = ['dateName' => 'cl.date_triggered'];
        $eventTypes['upcoming']  = ['dateName' => 'cl.trigger_date'];

        $query = $this->connection->createQueryBuilder();
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'cl')
            ->leftJoin('cl', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'cl.campaign_id = c.id')
            ->leftJoin('cl', MAUTIC_TABLE_PREFIX.'leads', 'l', 'cl.lead_id = l.id')
            ->leftJoin('cl', MAUTIC_TABLE_PREFIX.'campaign_events', 'ce', 'cl.event_id = ce.id')
            ->leftJoin('cl', MAUTIC_TABLE_PREFIX.'categories', 'cat', 'cat.id = c.category_id AND cat.bundle=:bundle')
            ->setParameter('bundle', 'campaign')
            ->setParameter('start', $dates['start_date'])
            ->setParameter('end', $dates['end_date'])
            ->setFirstResult(0)
            ->setMaxResults(50);

        foreach ($eventTypes as $eventKey => $eventType) {
            $query->select($commonSelect.', '.$eventType['dateName'].' AS start')
                ->where($query->expr()->andX(
                    $query->expr()->gte($eventType['dateName'], ':start'),
                    $query->expr()->lte($eventType['dateName'], ':end')
                ));
            if ('upcoming' == $eventKey) {
                $query->andWhere($query->expr()->gte($eventType['dateName'], ':now'))
                    ->setParameter('now', $now->toUtcString());
            }
            $results = $query->execute()->fetchAll();

            // We need to convert the date to a ISO8601 compliant string
            foreach ($results as &$object) {
                if ($object['firstname'] || $object['lastname']) {
                    $leadName = $object['firstname'].' '.$object['lastname'];
                } else {
                    $leadName = $this->translator->trans('mautic.lead.lead.anonymous');
                }
                $date                  = new DateTimeHelper($object['start']);
                $object['start']       = $date->toLocalString(\DateTime::ISO8601);
                $object['url']         = $this->router->generate('mautic_campaign_action', ['objectAction' => 'view', 'objectId' => $object['campaign_id']], true);
                $object['attr']        = 'data-toggle="ajax"';
                $object['description'] = $this->translator->trans('mautic.campaign.event.'.$eventKey.'.description', ['%campaign%' => $object['campaign_name'], '%lead%' => $leadName]);
                $object['title']       = $this->translator->trans('mautic.campaign.event.'.$eventKey, ['%event%' => $object['event_name']]);
            }

            $event->addEvents($results);
        }
    }
}
