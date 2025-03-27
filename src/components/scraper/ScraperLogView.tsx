
import { ScrollArea } from "@/components/ui/scroll-area";

interface LogEntry {
  timestamp: string;
  type: "info" | "error" | "success" | "warning";
  message: string;
}

interface ScraperLogViewProps {
  logs: LogEntry[];
}

const ScraperLogView = ({ logs }: ScraperLogViewProps) => {
  return (
    <div>
      <h3 className="text-sm font-medium mb-3">Scraper Log</h3>
      <ScrollArea className="h-[250px] border rounded-md bg-muted/10 p-4">
        {logs.length > 0 ? (
          <div className="space-y-2">
            {logs.map((log, index) => (
              <div key={index} className="text-xs">
                <span className="text-muted-foreground">[{log.timestamp}]</span>{" "}
                <span className={
                  log.type === "error" ? "text-red-500 font-medium" : 
                  log.type === "success" ? "text-green-500 font-medium" : 
                  log.type === "warning" ? "text-amber-500 font-medium" :
                  "text-foreground"
                }>
                  {log.message}
                </span>
              </div>
            ))}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground text-center py-4">
            Run the test to see scraper logs
          </p>
        )}
      </ScrollArea>
    </div>
  );
};

export default ScraperLogView;
