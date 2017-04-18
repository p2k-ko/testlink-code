<?php
namespace GitlabApi;

/**
 * Gitlab Rest Client
 *
 * Implementation taken from the JIRA/TestLink bugtracker integration
 *
 * @author     Original Author https://github.com/FayP / http://faypickering.com
 * @author     Francisco Mancardi <francisco.mancardi@gmail.com>
 * @author     Stephan Schneider <stephan.schneider@fhr.fraunhofer.de>
 */

class gitlab
{
    protected $host;
    protected $token;
    protected $proxy;

    /**
     * Constructing a new api instance
     */
    public function __construct(array $cfg = array())
    {
        if (!is_null($cfg)) {
            $k2trim = array('token','host');
            foreach ($k2trim as $tg) {
                $this->$tg = (isset($cfg[$tg])) ? trim($cfg[$tg]) : null;
            }
            $this->proxy = isset($cfg['proxy']) ? $cfg['proxy'] : null;
        }
        $this->request = new RestRequest();
        $this->request->token = $this->token;
        $this->request->proxy = $this->proxy;

        $this->configCheck();

        $this->host = trim($this->host, "/") . '/';
        if (($last = $this->host[strlen($this->host)-1]) != '/') {
            $this->host .= '/';
        }
    }

    /**
     *
     */
    private function configCheck()
    {
        if (is_null($this->host) || $this->host == '') {
            throw new \Exception('Missing or Empty host (url to API) - unable to continue');
        }
        if (is_null($this->request->token) || $this->request->token == '') {
            throw new \Exception('Missing or Empty username - unable to continue');
        }
    }

    /**
     *
     */
    public function testLogin()
    {
        $version = $this->getVersion();
        if (!empty($version) && $this->request->lastRequestStatus()) {
            return true;
        }

        return false;
    }

    /**
     * https://docs.gitlab.com/ce/api/
     * https://docs.gitlab.com/ce/api/version.html
     *
     * The version and revision of the GitLab installation is returned by this
     * API call.
     */
    public function getVersion()
    {
        $this->request->openConnect($this->host . 'version', 'GET');
        $this->request->execute();

        $version = json_decode($this->request->getResponseBody());

        return $version;
    }

    /**
     * https://docs.gitlab.com/ce/api/users.html#users
     */
    public function getUser($username)
    {
        $this->request->openConnect($this->host . 'users?username=' . $username, 'GET');
        $this->request->execute();
        $user = json_decode($this->request->getResponseBody());

        return $user;
    }

    /**
     *
     */
    public function getStatuses()
    {
        $this->request->openConnect($this->host . 'status', 'GET');
        $this->request->execute();
        $statuses = json_decode($this->request->getResponseBody());
        $returnStatuses = array();
        foreach ($statuses as $status) {
            $returnStatuses[$status->id] = $status->name;
        }

        return $returnStatuses;
    }

    public function getTransitions($issueKey)
    {
        $this->request->openConnect($this->host . 'issue/' . $issueKey . '/transitions', 'GET');
        $this->request->execute();
        if ($result = json_decode($this->request->getResponseBody())) {
            $returnTransitions = array();
            foreach ($result->transitions as $transition) {
                $returnTransitions[$transition->id] = $transition->name;
            }
            return $returnTransitions;
        }

        return false;
    }

    public function getChangelog($issueKey, $historyAsText = true)
    {
        $this->request->openConnect($this->host . 'issue/' . $issueKey . '/?expand=changelog', 'GET');
        $this->request->execute();
        if ($result = json_decode($this->request->getResponseBody())) {
            if (!isset($result->changelog)) {
                return false;
            }
            $changeLog = array();
            $histories = $result->changelog->histories;
            if ($historyAsText) {
                foreach ($histories as $history) {
                    $changeLog[$history->author->name] = array(
                        'Created:' => $history->created,
                        var_export($history->items, true)
                    );
                }
            } else {
                foreach ($histories as $history) {
                    $changeLog[$history->author->name] = array(
                        'Created:' => $history->created,
                        $history->items, true
                    );
                }
            }
            return $changeLog;
        }

        return false;
    }

    public function getComments($issueKey)
    {
        $this->request->openConnect($this->host . 'issue/' . $issueKey . '/comment?expand', 'GET');
        $this->request->execute();
        $result = json_decode($this->request->getResponseBody());
        if (isset($result->comments)) {
            return $result->comments;
        }

        return false;
    }

    public function queryIssue($query)
    {
        function createPairs($obj)
        {
            $str = "";
            foreach ($obj as $key => $value) {
                if ($key != 'jql') {
                    $str .= "$key=$value&";
                } else {
                    $str .= trim($value, '"\'@') . '&';
                }
            }
            return rtrim($str, '&');
        }
        $qs = createPairs($query);
        $qs = urlencode($qs);
        $this->request->OpenConnect($this->host . 'search?jql=' . $qs);
        $this->request->execute();
        $result = json_decode($this->request->getResponseBody());
        if (isset($result->issues)) {
            return $result->issues;
        }

        return false;
    }

    /**
     *
     * @param array $issueFields using 'fields' member
     *
     * Here's an example:
     *
     * $issueFields = array('fields' =>
     *                       array('project' => array('key' => (string)'ZOFF'),
     *                             'summary' => 'My First JIRA Issue via REST',
     *                             'description' => '',
     *                             'issuetype' => array( 'id' => 1)
     *                            )
     *                     );
     *
     * For more details about fields:
     * https://developer.atlassian.com/display/JIRADEV/
     *       JIRA+REST+API+Example+-+Create+Issue#JIRARESTAPIExample-CreateIssue-Examplesofcreatinganissue
     *
     * https://developer.atlassian.com/display/JIRADEV/
     *       JIRA+REST+API+Example+-+Discovering+meta-data+for+creating+issues
     *
     *
     * @return  object reponse body (ATTENTION: can be null if something wrong has happened)
     *          properties: id,key,self
     *          Example:
     *          {"id":"12505","key":"ZOFF-186","self":"https://testlink.atlassian.net/rest/api/latest/issue/12505"}
     *
     */
    public function createIssue($issueFields)
    {
        $issue_url = $this->host .
                                  'projects/' .
                                  $issueFields['fields']['project'] .
                                  '/issues?title=' .
                                  rawurlencode($issueFields['fields']['summary']) .
                                  '&description=' .
                                  rawurlencode($issueFields['fields']['description']) .
                                  '&lables=' .
                                  rawurlencode($issueFields['fields']['lable']);

        $this->request->openConnect($issue_url, 'POST');
        $this->request->execute();

        $retval = array('response' =>  json_decode($this->request->getResponseBody()),
                        'status' => $this->request->lastRequestStatus());

        return $retval;
    }

    /**
     *
     *
     */
    public function addAttachment($filename, $issueKey)
    {
        $this->request->openConnect($this->host . 'issue/' . $issueKey . '/attachments', 'POST', null, $filename);
        $this->request->execute();

        return $this->request->lastRequestStatus();
    }

    /**
     *
     * @param array $issueFields using 'fields' member
     *
     */
    public function updateIssue($issueFields, $issueKey)
    {
        $this->request->openConnect($this->host . 'issue/' . $issueKey, 'PUT', $issueFields);
        $this->request->execute();

        return $this->request->lastRequestStatus();
    }

    public function transitionIssue($issue, $transitionId)
    {
        $transitionId = (int) $transitionId;
        $data = array('transition' => array('id' => $transitionId));
        $this->request->openConnect($this->host . 'issue/' . $issue . '/transitions', 'POST', $data);
        $this->request->execute();

        return $this->request->lastRequestStatus();
    }

    public function addComment($comment, $issueKey)
    {
        $newComment = array(
            "body"  => $comment,
        );

        $this->request->openConnect($this->host . 'issue/' . $issueKey . '/comment', 'POST', $newComment);
        $this->request->execute();

        return $this->request->lastRequestStatus();
    }

    public function getIssue($projectId, $issueIid)
    {
        $issue_url = $this->host .
                                  'projects/' .
                                  $projectId .
                                  '/issues/' .
                                  $issueIid;

        $this->request->openConnect($issue_url, 'GET');
        $this->request->execute();

        $retval = array(
            'status' => $this->request->lastRequestStatus(),
            'response' => json_decode($this->request->getResponseBody())
        );

        return $retval;
    }

    /**
     * From JIRA Docs
     *
     * Returns a list of active users that match the search string and
     * have all specified permissions for the project or issue.
     * This resource can be accessed by users with ADMINISTER_PROJECT permission for the project or
     * global ADMIN or SYSADMIN rights.
     */
    public function checkUserPermissionsForProject($username, $projectKey, $permissionCSV)
    {
        $uri = $this->host . 'user/permission/search/?' .
               "username=$username&permissions=$permissionCSV&projectKey=$projectKey";
        $this->request->openConnect($uri, 'GET');
        $this->request->execute();
        $user = json_decode($this->request->getResponseBody());

        return $user;
    }


    /**
     * get available issue types
     *
     * @return mixed
     */
    public function getIssueTypes()
    {
        $this->request->openConnect($this->host . 'issuetype', 'GET');
        $this->request->execute();
        $items = json_decode($this->request->getResponseBody());
        return $items;
    }

   /**
     * get available priorities
     *
     * @return mixed
     */
    public function getPriorities()
    {
        $this->request->openConnect($this->host . 'priority', 'GET');
        $this->request->execute();
        $items = json_decode($this->request->getResponseBody());
        return $items;
    }

    /**
     * get available versions
     *
     * @return mixed
     */
    public function getVersions($projectKey)
    {
        $uri = $this->host . "project/{$projectKey}/versions";
        $this->request->openConnect($uri, 'GET');
        $this->request->execute();
        $items = json_decode($this->request->getResponseBody());
        if (is_array($items)) {
            return $items;
        } else {
            // ATTENTION \Exception in order to use PHP object.
            $msg = "Error Processing Request - " . __METHOD__ . ' ' .
                   implode('/', $items->errorMessages);
            throw new \Exception($msg, 999);
        }
    }


    /**
     * get available components
     *
     * @return mixed
     */
    public function getComponents($projectKey)
    {
        $uri = $this->host . "project/{$projectKey}/components";
        $this->request->openConnect($uri, 'GET');
        $this->request->execute();
        $items = json_decode($this->request->getResponseBody());

        if (is_array($items)) {
            return $items;
        } else {
            // ATTENTION \Exception in order to use PHP object.
            $msg = "Error Processing Request - " . __METHOD__ . ' ' .
                   implode('/', $items->errorMessages);
            throw new \Exception($msg, 999);
        }
    }

    /**
     * get available versions
     *
     * @return mixed
     */
    public function getProject($projectKey)
    {
        $uri = $this->host . "project/{$projectKey}";
        $this->request->openConnect($uri, 'GET');
        $this->request->execute();

        $obj = json_decode($this->request->getResponseBody());
        if (!is_null($obj)) {
            if (property_exists($obj, 'errorMessages')) {
                // ATTENTION \Exception in order to use PHP object.
                $msg = "Error Processing Request - " . __METHOD__ . ' ' .
                       implode('/', $obj->errorMessages);
                throw new \Exception($msg, 999);
            }
        }
        return $obj;
    }

    /**
     * return a map where main key is projectkey (if call has returned infor
     * for this key)
     * Each element is an map with issueTypeID as key, and each element inside
     * this map has to elements with two keys:
     * - issueTypeName
     * - fields => array with field names
     *
     * Here a partial example for project with key ZOFF
     * array(1) {
     *    ["ZOFF"]=>
     *         array(7) {
     *           [1]=>
     *           array(2) {
     *             ["issueTypeName"]=> "Bug"
     *             ["fields"]=> array(21) {
     *                           ["summary"] => "summary"
     *                           ["reporter"] => "reporter"
     */
    public function getCreateIssueFields($projectKeys=null)
    {
        $opt = 'expand=projects.issuetypes.fields';
        $items = $this->getCreateIssueMetadata($projectKeys, $opt);
        $ret = null;
        if (!is_null($items) && count($items->projects) > 0) {
            $ro = &$items->projects;
            foreach ($ro as $ele) {
                $ret[$ele->key] = array();
                $rx = &$ele->issuetypes;
                foreach ($rx as $it) {
                    $ret[$ele->key][$it->id]['issueTypeName'] = $it->name;
                    foreach ($it->fields as $field) {
                        $ret[$ele->key][$it->id]['fields'][$field->key] = $field->key;
                    }
                }
            }
            //return $items->projects;
        }
        return $ret;
    }



    /**
     * https://docs.atlassian.com/jira/REST/cloud/#api/2/issue-getCreateIssueMeta
     *
     * https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/
     *       jira-rest-api-tutorials/jira-rest-api-example-discovering-meta-data-for-creating-issues
     *
     *
     * curl -D- -u fred:fred -X GET -H "Content-Type: application/json" \
     *      http://kelpie9:8081/rest/api/2/issue/createmeta
     *
     * curl -D- -u fred:fred -X GET -H "Content-Type: application/json" \
     *      http://kelpie9:8081/rest/api/2/issue/createmeta?projectKeys=QA
     *
     * curl -D- -u fred:fred -X GET -H "Content-Type: application/json" \
     *      http://kelpie9:8081/rest/api/2/issue/createmeta?projectKeys=QA,XSS
     *
     * From Atlassian documentation
     * projectKeys  string
     *              lists the projects with which to filter the results.
     *              If absent, all projects are returned.
     *              This parameter can be comma-separated list.
     *              Specifiying a project that does not exist
     *              (or that you cannot create issues in) is not an error,
     *              but it will not be in the results.
     *
     * opt can contain issuetypeIds, issuetypeNames, expand=projects.issuetypes.fields.
     *     Fields will only be returned if expand=projects.issuetypes.fields.
     */
    public function getCreateIssueMetadata($projectKeys=null, $opt=null)
    {
        $cmd = $this->host . 'issue/createmeta';
        $ope = '?';
        if (!is_null($projectKeys)) {
            $cmd .= $ope . 'projectKeys=' . $projectKeys;
            $ope = '&';
        }

        if (!is_null($opt)) {
            $cmd .= $ope . $opt;
        }

        $this->request->openConnect($cmd, 'GET');
        $this->request->execute();
        $items = json_decode($this->request->getResponseBody());

        return $items;
    }
}
