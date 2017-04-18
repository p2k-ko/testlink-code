<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 *
 * @filesource	gitlabrestInterface.class.php
 * @author Francisco Mancardi
 * @author Stephan Schneider <stephan.schneider@fhr.fraunhofer.de>
 *
 * @internal revisions
 * @since 1.9.16
 *
**/
require_once(TL_ABS_PATH . "/third_party/gitlab-rest-api/lib/gitlab-rest-api.php");
require_once(TL_ABS_PATH . "/third_party/gitlab-rest-api/lib/RestRequest.php");
class gitlabrestInterface extends issueTrackerInterface
{
    private $APIClient;
    private $issueDefaults;
    private $issueOtherAttr = null; // see
    private $translate = null;
    private $gitlabCfg;

    public $defaultResolvedStatus;

    /**
     * Construct and connect to GitLab BTS.
     *
     * @param str $type (see tlIssueTracker.class.php $systems property)
     * @param xml $cfg
     **/
    public function __construct($type, $config, $name)
    {
        $this->name = $name;
        $this->methodOpt['buildViewBugLink'] = array('addSummary' => true, 'colorByStatus' => false);

        $this->defaultResolvedStatus = array();
        $this->defaultResolvedStatus[] = array('code' => 0, 'verbose' => 'opened');
        $this->defaultResolvedStatus[] = array('code' => 1, 'verbose' => 'closed');

        if (!$this->setCfg($config)) {
            return false;
        }
        $this->completeCfg();
        $this->setResolvedStatusCfg();
        $this->connect();
    }

    /**
     *
     * check for configuration attributes than can be provided on
     * user configuration, but that can be considered standard.
     * If they are MISSING we will use 'these carved on the stone values'
     * in order	to simplify configuration.
     *
     *
     **/
    public function completeCfg()
    {
        $base = trim($this->cfg->uribase, "/") . '/'; // be sure no double // at end
        if (!property_exists($this->cfg, 'uriview')) {
            $this->cfg->uriview = $base . 'projects/' . $this->cfg->projectidentifier . '/issues';
        }

        if (!property_exists($this->cfg, 'uricreate')) {
            $this->cfg->uricreate = $base . 'projects/' . $this->cfg->projectidentifier . '/issues';
        }
    }


    /**
   * useful for testing
   *
   *
   **/
    public function getAPIClient()
    {
        return $this->APIClient;
    }

  /**
   * checks id for validity
   *
   * @param string issueID
   *
   * @return bool returns true if the bugid has the right format, false else
   **/
  public function checkBugIDSyntax($issueID)
  {
      return $this->checkBugIDSyntaxNumeric($issueID);
  }

  /**
   * establishes connection to the bugtracking system
   *
   * @return bool
   *
   **/
  public function connect()
  {
      $processCatch = false;

      try {
          // CRITIC NOTICE for developers
            // $this->cfg is a simpleXML Object, then seems very conservative and safe
            // to cast properties BEFORE using it.
          $this->gitlabCfg = array('token' => (string)trim($this->cfg->apikey),
                       'host' => (string)trim($this->cfg->uribase));

          $this->gitlabCfg['proxy'] = config_get('proxy');
          if (!is_null($this->gitlabCfg['proxy'])) {
              if (is_null($this->gitlabCfg['proxy']->host)) {
                  $this->gitlabCfg['proxy'] = null;
              }
          }

          $this->APIClient = new GitlabApi\gitlab($this->gitlabCfg);

          try {
              $items = $this->APIClient->getVersion();
              $this->connected = !is_null($items);
              unset($items);
          } catch (Exception $e) {
              $processCatch = true;
          }
      } catch (Exception $e) {
          $processCatch = true;
      }

      if ($processCatch) {
          $logDetails = '';
          foreach (array('uribase','apikey') as $v) {
              $logDetails .= "$v={$this->cfg->$v} / ";
          }
          $logDetails = trim($logDetails, '/ ');
          $this->connected = false;
          tLog(__METHOD__ . " [$logDetails] " . $e->getMessage(), 'ERROR');
      }
  }

  /**
   *
   *
   **/
    public function isConnected()
    {
        return $this->connected;
    }

  /**
   *
   *
   **/
    public function getIssue($issueID)
    {
        if (!$this->isConnected()) {
            tLog(__METHOD__ . '/Not Connected ', 'ERROR');
            return false;
        }

        $issue = null;
        try {
            // A Gitlab issue iid is bound to the project context. GetIssue requires
            // both, the project id and the issue iid.
            $op = $this->APIClient->getIssue($this->cfg->projectidentifier, $issueID);

            if (!is_null($op) && $op['status'] == true) {
                $issue = $op['response'];
                // We are going to have a set of standard properties
                $issue->id = $issue->iid;
                $issue->summary = $issue->title;
                $issue->statusCode = $issue->state;
                $issue->statusVerbose = $issue->state;

                $issue->IDHTMLString = "<b>{$issueID} : </b>";
                $issue->statusHTMLString = $issue->statusCode;
                $issue->summaryHTMLString = $issue->summary;

                $issue->isResolved = ($issue->state == 'closed' ? true : false);
            }
        } catch (Exception $e) {
            tLog("GitLab Issue ID $issueID - " . $e->getMessage(), 'WARNING');
            $issue = null;
        }
        return $issue;
    }


    /**
     * Returns status for issueID
     *
     * @param string issueID
     *
     * @return null in case of error. 1 -> closed 0 -> opened
     **/
    public function getIssueStatusCode($issueID)
    {
        $issue = getIssue($issueID);

        $retval = null;
        if (!is_null($issue)) {
            $retval = ($issue->state == 'closed' ? 1 : 0);
        }

        return $retval;
    }

    /**
     * Returns status in a readable form (HTML context) for the bug with the given id
     *
     * @param string issueID
     *
     * @return string
     *
     **/
    public function getIssueStatusVerbose($issueID)
    {
        $issue = getIssue($issueID);

        $retval = null;
        if (!is_null($issue)) {
            $retval = $issue->state;
        }

        return $retval;
    }

    /**
     *
     * @param string issueID
     *
     * @return string
     *
     **/
    public function getIssueSummaryHTMLString($issueID)
    {
        $issue = getIssue($issueID);

        $retval = null;
        if (!is_null($issue)) {
            $retval = $issue->title;
        }

        return $retval;
    }

  /**
     * @param string issueID
   *
   * @return bool true if issue exists on BTS
   **/
  public function checkBugIDExistence($issueID)
  {
      $issue = getIssue($issueID);
      $retval = (is_null($issue) ? false : true);
      return $retval;
  }

  /**
   *
   * From GitLab API documentation (@20170404)
   * Parameters:
   *
   * issue - A hash of the issue attributes:
   * id - integer - Project ID - required
   * title - string - Title of the issue - required
   * description - string - Detailed description - optional
   * confidential - boolean - Set an issue to be confidential - optional
   * assignee_id - integer - The ID of the user to assign issue - optional
   * milestone_id - integer - ID of the milestone to assign issue - optional
   * lables - string - Comma separated list of lable names - optional
   * created_at - string - Date-Time string needs admin rights - optional
   * due_date - string - Date sting in Year-Month-Day -optional
   *
   */
  public function addIssue($summary, $description, $opt=null)
  {
      // Check mandatory info
      if (!property_exists($this->cfg, 'projectidentifier')) {
          throw new exception(__METHOD__ . " project identifier is MANDATORY");
      }


      try {
          $issue = array('fields' =>
                         array('project' => (int)$this->cfg->projectidentifier,
                               'summary' => $summary,
                               'description' => $description,
                               'lable' => 'bug'));


          // Call the rest api and create a new issue in the BTS
          $op = $this->APIClient->createIssue($issue);

          $ret = array('status_ok' => false, 'id' => null, 'msg' => 'ko');

          if (!is_null($op)) {
              if ($op['status'] != true) {
                  $ret['msg'] = __FUNCTION__ . ":Failure:Gitlab Message:\n";
                  if (isset($op['response']->{'message'})) {
                      $ret['msg'] .= $op['response']->{'message'};
                  }
              } else {
                  logWarningEvent('Here we are looking at some magic...');
                  logWarningEvent($op['response']->{'iid'});
                  $ret = array('status_ok' => true, 'id' => $op['response']->{'iid'},
                           'msg' => sprintf(lang_get('gitlab_bug_created'), $summary, $issue['fields']['project']));
              }
          }
      } catch (Exception $e) {
          $msg = "Create GitLab Ticket FAILURE => " . $e->getMessage();
          tLog($msg, 'WARNING');
          // $ret = array('status_ok' => false, 'id' => -1, 'msg' => $msg . ' - serialized issue' . serialize($issue));
          $ret = array('status_ok' => false, 'id' => -1, 'msg' => $msg . ' - serialized issue');
      }

      return $ret;
  }


  /**
   *
   */
  public function addNote($issueID, $noteText, $opt=null)
  {
      return false;
  }

  /**
   *
   * @author francisco.mancardi@gmail.com>
  *  @author stephan.schneider@fhr.fraunhofer.de
   **/
    public static function getCfgTemplate()
    {
        $tpl = "<!-- Template " . __CLASS__ . " -->\n" .
                   "<issuetracker>\n" .
                   "<apikey>GITLAB API KEY</apikey>\n" .
                   "<uribase>http://gitlab.org</uribase>\n" .
           "<uriview>https://gitlab.example.com/api/v4/projects/<ID>/issue</uriview> \n" .
                   "<!-- Project Identifier is NEEDED ONLY if you want to create issues from TL -->\n" .
                   "<projectidentifier>GITLAB PROJECT IDENTIFIER\n" .
           " You can use numeric id or identifier string \n" .
           "</projectidentifier>\n" .
                   "</issuetracker>\n";
        return $tpl;
    }

 /**
  *
  **/
  public function canCreateViaAPI()
  {
      return (property_exists($this->cfg, 'projectidentifier'));
  }
}
