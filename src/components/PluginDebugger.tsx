
import { useEffect, useState, useCallback, memo } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Separator } from "@/components/ui/separator";
import { Bug, RefreshCw, CheckCircle, AlertTriangle } from "lucide-react";

type LogEntry = {
  timestamp: string;
  message: string;
  type: "info" | "error" | "success";
};

const StatusIcon = memo(({ status }: { status: "active" | "inactive" | "unknown" }) => {
  switch (status) {
    case "active":
      return <CheckCircle className="h-5 w-5 text-green-500" />;
    case "inactive":
      return <AlertTriangle className="h-5 w-5 text-yellow-500" />;
    default:
      return <Bug className="h-5 w-5 text-gray-400" />;
  }
});

const LogEntryItem = memo(({ log }: { log: LogEntry }) => (
  <div className="flex flex-col text-sm">
    <div className="flex items-center gap-2">
      <span className="text-xs text-muted-foreground">
        {new Date(log.timestamp).toLocaleTimeString()}
      </span>
      <span className={`
        ${log.type === "info" ? "text-blue-500" : ""}
        ${log.type === "error" ? "text-red-500" : ""}
        ${log.type === "success" ? "text-green-500" : ""}
        font-medium
      `}>
        {log.message}
      </span>
    </div>
  </div>
));

const PluginDebugger = () => {
  const [logs, setLogs] = useState<LogEntry[]>([]);
  const [loading, setLoading] = useState(false);
  const [pluginStatus, setPluginStatus] = useState<"active" | "inactive" | "unknown">("unknown");

  const fetchLogs = useCallback(async () => {
    if (loading) return; // Prevent multiple simultaneous requests
    
    setLoading(true);
    
    try {
      // In a real implementation, this would make an AJAX call to the WordPress backend
      // to fetch real logs using the wp_ajax action
      
      // Simulate an API call
      await new Promise(resolve => setTimeout(resolve, 1500));
      
      // Sample logs for demonstration
      const sampleLogs: LogEntry[] = [
        {
          timestamp: new Date().toISOString(),
          message: "Plugin status check performed",
          type: "info"
        },
        {
          timestamp: new Date(Date.now() - 60000).toISOString(),
          message: pluginStatus === "active" 
            ? "Plugin is active and functioning properly" 
            : "Plugin appears to be inactive or experiencing issues",
          type: pluginStatus === "active" ? "success" : "error"
        }
      ];
      
      setLogs(sampleLogs);
    } catch (error) {
      console.error("Error fetching logs:", error);
      
      // Add error to logs
      setLogs(prev => [
        {
          timestamp: new Date().toISOString(),
          message: `Error fetching logs: ${error instanceof Error ? error.message : 'Unknown error'}`,
          type: "error"
        },
        ...prev
      ]);
    } finally {
      setLoading(false);
    }
  }, [loading, pluginStatus]);

  const checkPluginStatus = useCallback(async () => {
    if (loading) return; // Prevent multiple simultaneous requests
    
    setLoading(true);
    
    try {
      // In a real implementation, this would make an AJAX call to check if the plugin is active
      // For demonstration, we'll randomly set the status
      
      await new Promise(resolve => setTimeout(resolve, 1500));
      
      // Sample status for demonstration
      const status = Math.random() > 0.5 ? "active" : "inactive";
      setPluginStatus(status as "active" | "inactive");
      
      // Add a log entry
      setLogs(prev => [
        {
          timestamp: new Date().toISOString(),
          message: `Plugin status check: ${status}`,
          type: status === "active" ? "success" : "error"
        },
        ...prev
      ]);
    } catch (error) {
      console.error("Error checking plugin status:", error);
      
      // Add error to logs
      setLogs(prev => [
        {
          timestamp: new Date().toISOString(),
          message: `Error checking status: ${error instanceof Error ? error.message : 'Unknown error'}`,
          type: "error"
        },
        ...prev
      ]);
    } finally {
      setLoading(false);
    }
  }, [loading]);

  useEffect(() => {
    // Check plugin status on component mount
    checkPluginStatus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <Card className="w-full">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Bug className="h-5 w-5" />
          Plugin Debugger
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span>Plugin Status:</span>
            <span className="flex items-center gap-1 font-medium">
              <StatusIcon status={pluginStatus} />
              {pluginStatus === "active" && "Active"}
              {pluginStatus === "inactive" && "Inactive"}
              {pluginStatus === "unknown" && "Unknown"}
            </span>
          </div>
          <Button 
            variant="outline" 
            size="sm" 
            onClick={checkPluginStatus}
            disabled={loading}
          >
            {loading ? (
              <RefreshCw className="h-4 w-4 animate-spin" />
            ) : (
              "Check Status"
            )}
          </Button>
        </div>
        
        <Separator />
        
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-medium">Plugin Logs</h3>
          <Button 
            variant="outline" 
            size="sm" 
            onClick={fetchLogs}
            disabled={loading}
          >
            {loading ? (
              <RefreshCw className="h-4 w-4 animate-spin mr-2" />
            ) : (
              <RefreshCw className="h-4 w-4 mr-2" />
            )}
            Refresh Logs
          </Button>
        </div>
        
        <ScrollArea className="h-[200px] w-full rounded-md border p-4">
          {logs.length === 0 ? (
            <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
              No logs available
            </div>
          ) : (
            <div className="space-y-3">
              {logs.map((log, index) => (
                <LogEntryItem key={index} log={log} />
              ))}
            </div>
          )}
        </ScrollArea>
        
        <div className="text-xs text-muted-foreground">
          <p>
            Check your WordPress error logs for more detailed information. 
            Typically located at /wp-content/debug.log or in your server error logs.
          </p>
        </div>
      </CardContent>
    </Card>
  );
};

export default memo(PluginDebugger);
