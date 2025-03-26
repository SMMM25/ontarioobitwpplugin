
import { useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { ScrollArea } from "@/components/ui/scroll-area";
import { toast } from "sonner";
import { scrapeObituaries, DEFAULT_SCRAPER_CONFIG } from "@/utils/scraperUtils";
import { AlertCircle, CheckCircle, Loader2, RefreshCw } from "lucide-react";

const ScraperDebug = () => {
  const [isScrapingNow, setIsScrapingNow] = useState(false);
  const [scraperLog, setScraperLog] = useState<Array<{
    timestamp: string;
    type: "info" | "error" | "success";
    message: string;
  }>>([]);
  const [scrapedSources, setScrapedSources] = useState<string[]>([]);
  const [connectionErrors, setConnectionErrors] = useState<string[]>([]);

  const logMessage = (type: "info" | "error" | "success", message: string) => {
    const timestamp = new Date().toLocaleTimeString();
    setScraperLog(prev => [...prev, { timestamp, type, message }]);
  };

  const handleTestScrape = async () => {
    setIsScrapingNow(true);
    setScraperLog([]);
    setScrapedSources([]);
    setConnectionErrors([]);
    
    // Log start
    logMessage("info", "Starting test scrape of obituary sources...");
    
    try {
      // Test each region separately to isolate issues
      for (const region of DEFAULT_SCRAPER_CONFIG.regions) {
        logMessage("info", `Testing connection to ${region} sources...`);
        
        try {
          // Create a configuration with just this region
          const testConfig = {
            ...DEFAULT_SCRAPER_CONFIG,
            regions: [region],
            maxAge: 30, // Increase the max age to find more obituaries
          };
          
          // Attempt to scrape this region
          const result = await scrapeObituaries(testConfig);
          
          if (result.success) {
            logMessage("success", `Successfully connected to ${region} source${result.data?.length ? ` (found ${result.data.length} obituaries)` : ''}`);
            setScrapedSources(prev => [...prev, region]);
            
            // If no obituaries found despite successful connection
            if (!result.data?.length) {
              logMessage("info", `No obituaries found from ${region} within the time period. Try increasing maxAge.`);
            }
          } else {
            logMessage("error", `Failed to scrape ${region}: ${result.message}`);
            setConnectionErrors(prev => [...prev, region]);
          }
        } catch (error) {
          logMessage("error", `Exception scraping ${region}: ${error instanceof Error ? error.message : String(error)}`);
          setConnectionErrors(prev => [...prev, region]);
        }
        
        // Small delay between requests to avoid overwhelming the server
        await new Promise(resolve => setTimeout(resolve, 1000));
      }
      
      // Log completion
      const successCount = scrapedSources.length;
      const errorCount = connectionErrors.length;
      
      if (successCount && !errorCount) {
        logMessage("success", `All sources connected successfully. Check WordPress database for obituaries.`);
        toast.success("Scraper test completed", {
          description: "All sources tested successfully."
        });
      } else if (successCount && errorCount) {
        logMessage("info", `Test completed with ${successCount} successful and ${errorCount} failed connections.`);
        toast.info("Scraper test completed with issues", {
          description: `${errorCount} source(s) could not be connected.`
        });
      } else {
        logMessage("error", "No sources could be connected. Check your network and source configurations.");
        toast.error("Scraper test failed", {
          description: "No obituary sources could be connected."
        });
      }
    } catch (error) {
      logMessage("error", `Test scrape failed: ${error instanceof Error ? error.message : String(error)}`);
      toast.error("Scraper test failed", {
        description: "An unexpected error occurred."
      });
    } finally {
      setIsScrapingNow(false);
    }
  };

  return (
    <Card className="w-full">
      <CardHeader>
        <CardTitle className="text-xl font-semibold">Scraper Diagnostics</CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="space-y-2">
          <div className="flex flex-wrap gap-2 mb-4">
            <Button 
              variant="default" 
              onClick={handleTestScrape}
              disabled={isScrapingNow}
            >
              {isScrapingNow ? (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  Testing Sources...
                </>
              ) : (
                <>
                  <RefreshCw className="h-4 w-4 mr-2" />
                  Test Scraper Sources
                </>
              )}
            </Button>
          </div>
          
          {/* Display scraping status */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <Card className="border-border/50">
              <CardHeader className="py-3 px-4">
                <CardTitle className="text-sm font-medium">Successful Connections</CardTitle>
              </CardHeader>
              <CardContent className="py-3 px-4">
                {scrapedSources.length > 0 ? (
                  <div className="flex flex-wrap gap-2">
                    {scrapedSources.map((source) => (
                      <Badge key={source} variant="outline" className="bg-green-500/10 text-green-700 border-green-200">
                        <CheckCircle className="h-3 w-3 mr-1" />
                        {source}
                      </Badge>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    {isScrapingNow ? "Testing connections..." : "No successful connections yet"}
                  </p>
                )}
              </CardContent>
            </Card>
            
            <Card className="border-border/50">
              <CardHeader className="py-3 px-4">
                <CardTitle className="text-sm font-medium">Failed Connections</CardTitle>
              </CardHeader>
              <CardContent className="py-3 px-4">
                {connectionErrors.length > 0 ? (
                  <div className="flex flex-wrap gap-2">
                    {connectionErrors.map((source) => (
                      <Badge key={source} variant="outline" className="bg-red-500/10 text-red-700 border-red-200">
                        <AlertCircle className="h-3 w-3 mr-1" />
                        {source}
                      </Badge>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    {isScrapingNow ? "Testing connections..." : "No connection errors yet"}
                  </p>
                )}
              </CardContent>
            </Card>
          </div>
          
          {/* Separator */}
          <Separator className="my-4" />
          
          {/* Debug log */}
          <div>
            <h3 className="text-sm font-medium mb-3">Scraper Log</h3>
            <ScrollArea className="h-[250px] border rounded-md bg-muted/10 p-4">
              {scraperLog.length > 0 ? (
                <div className="space-y-2">
                  {scraperLog.map((log, index) => (
                    <div key={index} className="text-xs">
                      <span className="text-muted-foreground">[{log.timestamp}]</span>{" "}
                      <span className={
                        log.type === "error" ? "text-red-500 font-medium" : 
                        log.type === "success" ? "text-green-500 font-medium" : 
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
          
          {/* Help section */}
          <Alert className="mt-6 bg-amber-500/10 border-amber-200">
            <AlertCircle className="h-4 w-4" />
            <AlertTitle>Troubleshooting Tips</AlertTitle>
            <AlertDescription>
              <ul className="list-disc pl-5 mt-2 space-y-1 text-sm">
                <li>Check that your website has proper permissions to connect to external sites</li>
                <li>Ensure your web host allows outbound connections to the funeral home websites</li>
                <li>Verify the scraper selectors in the plugin PHP code match the current structure of the funeral home websites</li>
                <li>Try increasing the "maxAge" setting to find older obituaries</li>
                <li>Consider checking the WordPress debug log for PHP errors</li>
              </ul>
            </AlertDescription>
          </Alert>
        </div>
      </CardContent>
    </Card>
  );
};

export default ScraperDebug;
